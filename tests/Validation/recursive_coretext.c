/*
 * Native CoreText probe for the Recursive post-table reproducer.
 * Copyright (c) 2026-present Simon Andre. See LICENSE for licensing.
 */
#include <CoreText/CoreText.h>
#include <CoreGraphics/CoreGraphics.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>

#define WIDTH 128
#define HEIGHT 64

static bool render(const char *path, unsigned char *pixels)
{
    bool success = false;
    CGDataProviderRef data = CGDataProviderCreateWithFilename(path);
    CGFontRef graphics = data ? CGFontCreateWithDataProvider(data) : NULL;
    CTFontRef font = graphics ? CTFontCreateWithGraphicsFont(graphics, 16, NULL, NULL) : NULL;
    CGColorSpaceRef space = CGColorSpaceCreateDeviceRGB();
    CGContextRef context = space ? CGBitmapContextCreate(
        pixels, WIDTH, HEIGHT, 8, WIDTH * 4, space, kCGImageAlphaPremultipliedLast
    ) : NULL;

    if (!font || !context) {
        fprintf(stderr, "Unable to create font or bitmap: %s\n", path);
        goto cleanup;
    }

    CGContextSetRGBFillColor(context, 1, 1, 1, 1);
    CGContextFillRect(context, CGRectMake(0, 0, WIDTH, HEIGHT));
    CGContextSetRGBFillColor(context, 0, 0, 0, 1);
    CGContextSetShouldAntialias(context, true);
    CGContextSetShouldSmoothFonts(context, false);
    CGContextSetAllowsFontSubpixelPositioning(context, true);
    CGContextSetShouldSubpixelPositionFonts(context, true);
    CGContextSetAllowsFontSubpixelQuantization(context, false);
    CGContextSetShouldSubpixelQuantizeFonts(context, false);

    UniChar character = 'A';
    CGGlyph glyph = 0;
    if (!CTFontGetGlyphsForCharacters(font, &character, &glyph, 1)) {
        fprintf(stderr, "The font does not contain A: %s\n", path);
        goto cleanup;
    }

    CGPoint position = CGPointMake(10, 20);
    CTFontDrawGlyphs(font, &glyph, &position, 1, context);
    printf("%s A=%u named-X=%u capHeight=%g\n", path, glyph,
        CGFontGetGlyphWithGlyphName(graphics, CFSTR("X")), CTFontGetCapHeight(font));
    success = true;

cleanup:
    if (context) CGContextRelease(context);
    if (space) CGColorSpaceRelease(space);
    if (font) CFRelease(font);
    if (graphics) CGFontRelease(graphics);
    if (data) CGDataProviderRelease(data);
    return success;
}

int main(int argc, char **argv)
{
    if (argc != 3) {
        fprintf(stderr, "Usage: %s source.ttf post3.ttf\n", argv[0]);
        return 2;
    }

    unsigned char before[WIDTH * HEIGHT * 4] = {0};
    unsigned char after[WIDTH * HEIGHT * 4] = {0};
    if (!render(argv[1], before) || !render(argv[2], after)) return 3;

    int changed = 0;
    int maximum = 0;
    for (int pixel = 0; pixel < WIDTH * HEIGHT; pixel++) {
        int pixelDelta = 0;
        for (int channel = 0; channel < 4; channel++) {
            int delta = abs(before[pixel * 4 + channel] - after[pixel * 4 + channel]);
            if (delta > pixelDelta) pixelDelta = delta;
        }
        if (pixelDelta) {
            changed++;
            if (pixelDelta > maximum) maximum = pixelDelta;
        }
    }

    printf("changed pixels=%d maximum channel delta=%d\n", changed, maximum);
    return 0;
}
