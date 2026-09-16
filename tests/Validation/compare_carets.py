"""Compare ligature caret positions using HarfBuzz's public C API."""

import argparse
import ctypes as ct
import ctypes.util

from fontTools.ttLib import TTFont


def load_harfbuzz():
    library = ctypes.util.find_library("harfbuzz")
    if library is None:
        raise RuntimeError("HarfBuzz shared library is required for caret checks")
    hb = ct.CDLL(library)
    signatures = {
        "hb_blob_create_from_file_or_fail": (ct.c_void_p, [ct.c_char_p]),
        "hb_face_create": (ct.c_void_p, [ct.c_void_p, ct.c_uint]),
        "hb_face_get_upem": (ct.c_uint, [ct.c_void_p]),
        "hb_font_create": (ct.c_void_p, [ct.c_void_p]),
        "hb_ot_font_set_funcs": (None, [ct.c_void_p]),
        "hb_font_set_scale": (None, [ct.c_void_p, ct.c_int, ct.c_int]),
        "hb_font_set_ppem": (None, [ct.c_void_p, ct.c_uint, ct.c_uint]),
        "hb_font_set_var_coords_design": (None, [ct.c_void_p, ct.POINTER(ct.c_float), ct.c_uint]),
        "hb_ot_layout_get_ligature_carets": (
            ct.c_uint,
            [ct.c_void_p, ct.c_int, ct.c_uint32, ct.c_uint, ct.POINTER(ct.c_uint), ct.POINTER(ct.c_int32)],
        ),
    }
    for name in ("blob", "face", "font"):
        signatures[f"hb_{name}_destroy"] = (None, [ct.c_void_p])
    for name, (result, arguments) in signatures.items():
        function = getattr(hb, name)
        function.restype, function.argtypes = result, arguments
    return hb


def caret_positions(hb, path, ppem, weight):
    with TTFont(path) as source:
        coverage = source["GDEF"].table.LigCaretList.Coverage.glyphs
        if len(coverage) != 1:
            raise AssertionError("Fixture must retain exactly one caret-bearing ligature")
        glyph = source.getGlyphID(coverage[0])
    blob = hb.hb_blob_create_from_file_or_fail(str(path).encode())
    if not blob:
        raise RuntimeError(f"HarfBuzz cannot read {path}")
    face = hb.hb_face_create(blob, 0)
    font = hb.hb_font_create(face)
    try:
        hb.hb_ot_font_set_funcs(font)
        upem = hb.hb_face_get_upem(face)
        hb.hb_font_set_scale(font, upem, upem)
        hb.hb_font_set_ppem(font, ppem, ppem)
        if weight is not None:
            hb.hb_font_set_var_coords_design(font, (ct.c_float * 1)(weight), 1)
        count = ct.c_uint(16)
        positions = (ct.c_int32 * 16)()
        total = hb.hb_ot_layout_get_ligature_carets(font, 4, glyph, 0, ct.byref(count), positions)
        if total != 2 or count.value != 2:
            raise AssertionError(f"Expected two ligature carets, got {total}/{count.value}")
        return list(positions[:count.value])
    finally:
        hb.hb_font_destroy(font)
        hb.hb_face_destroy(face)
        hb.hb_blob_destroy(blob)


def compare(scenario, source, outputs):
    hb = load_harfbuzz()
    weights = (100, 400, 650, 900) if scenario == "variable-carets" else (None,)
    cases = [(ppem, weight) for ppem in (0, 12, 13) for weight in weights]
    expected = [caret_positions(hb, source, *case) for case in cases]
    if scenario == "variable-carets":
        if caret_positions(hb, source, 0, 400) == caret_positions(hb, source, 0, 900):
            raise AssertionError("Source caret variation is inactive")
    elif caret_positions(hb, source, 12, None) == caret_positions(hb, source, 13, None):
        raise AssertionError("Source caret Device adjustments are inactive")
    for output in outputs:
        for case, positions in zip(cases, expected):
            actual = caret_positions(hb, output, *case)
            if positions != actual:
                raise AssertionError(f"Caret positions differ for {output}, {case}: {positions} != {actual}")
    print(f"{scenario}: {len(cases) * len(outputs)} caret comparisons passed")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("carets", "variable-carets"))
    parser.add_argument("source")
    parser.add_argument("outputs", nargs="+")
    arguments = parser.parse_args()
    compare(arguments.scenario, arguments.source, arguments.outputs)
