import AppKit
import Foundation

// Laravel Executive AI — app icon.
//
// The mark is a geometric "E": a vertical stem with three arms of decreasing length. It reads as
// Executive at a glance and as the lines of a brief at 16 pt, which is the one size that decides
// whether an icon works. The top arm carries the light accent so the shape has a headline rather
// than three equal bars, which is what separates it from every generic list glyph.
//
// Palette is the product's own (DesignTokens): accent #0f5f70 on the ground, #63bfd1 for the
// headline arm, canvas #f4f4f2 for the rest. The icon therefore belongs to the same family as the
// interface instead of being decoration bolted on.

func rgb(_ hex: UInt32, _ alpha: CGFloat = 1) -> NSColor {
    NSColor(
        srgbRed: CGFloat((hex >> 16) & 0xFF) / 255,
        green: CGFloat((hex >> 8) & 0xFF) / 255,
        blue: CGFloat(hex & 0xFF) / 255,
        alpha: alpha
    )
}

let accent = rgb(0x0F5F70)
let accentDeep = rgb(0x0A4553)
let headline = rgb(0x63BFD1)
let canvas = rgb(0xF4F4F2)

/// Apple's macOS app-icon grid: the art sits inside the canvas with a margin and a continuous
/// rounded corner, rather than filling it. These ratios are the published ones for macOS.
func draw(size: CGFloat, into context: CGContext) {
    let inset = size * 0.0957          // 98/1024
    let side = size - inset * 2
    let radius = side * 0.2237         // 185/824, the macOS squircle
    let rect = CGRect(x: inset, y: inset, width: side, height: side)

    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = NSGraphicsContext(cgContext: context, flipped: false)

    // Ground: a quiet vertical gradient so the icon has depth without a glossy 2010 look.
    let path = NSBezierPath(roundedRect: rect, xRadius: radius, yRadius: radius)
    path.addClip()

    let gradient = NSGradient(colors: [accent, accentDeep])!
    gradient.draw(in: rect, angle: -90)

    // The mark, laid out on a 100-unit square centred in the art.
    let unit = side / 100
    let originX = rect.minX
    let originY = rect.minY
    func mark(_ x: CGFloat, _ y: CGFloat, _ w: CGFloat, _ h: CGFloat) -> CGRect {
        // y is given from the top, which is how the shape is easier to reason about.
        CGRect(x: originX + x * unit, y: originY + (100 - y - h) * unit, width: w * unit, height: h * unit)
    }

    // Thinner arms and wider gaps than the first pass: at 32 pt the three bars were nearly
    // touching and the E closed up into a block. The gap has to stay visible at the smallest size
    // or the mark stops being a letter.
    let stemWidth: CGFloat = 12
    let armHeight: CGFloat = 11
    let left: CGFloat = 27
    let top: CGFloat = 23
    let bottom: CGFloat = 77

    // Stem.
    canvas.setFill()
    NSBezierPath(roundedRect: mark(left, top, stemWidth, bottom - top), xRadius: stemWidth * 0.28, yRadius: stemWidth * 0.28).fill()

    // Arms: full, three-quarter, half. The top one is the headline.
    // Longest at the top (the headline), shortest in the middle, medium at the foot — the
    // proportions of a drawn E rather than three equal bars.
    let arms: [(CGFloat, CGFloat, NSColor)] = [
        (top, 46, headline),
        (50 - armHeight / 2, 31, canvas),
        (bottom - armHeight, 39, canvas),
    ]

    for (y, width, colour) in arms {
        colour.setFill()
        NSBezierPath(
            roundedRect: mark(left + stemWidth - 1, y, width, armHeight),
            xRadius: armHeight * 0.28,
            yRadius: armHeight * 0.28
        ).fill()
    }

    NSGraphicsContext.restoreGraphicsState()
}

func render(size: Int, to url: URL) throws {
    let dimension = CGFloat(size)
    guard let context = CGContext(
        data: nil,
        width: size,
        height: size,
        bitsPerComponent: 8,
        bytesPerRow: 0,
        space: CGColorSpace(name: CGColorSpace.sRGB)!,
        bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue
    ) else {
        throw NSError(domain: "icon", code: 1)
    }

    context.setAllowsAntialiasing(true)
    context.interpolationQuality = .high
    draw(size: dimension, into: context)

    guard let image = context.makeImage() else { throw NSError(domain: "icon", code: 2) }
    let rep = NSBitmapImageRep(cgImage: image)
    rep.size = NSSize(width: dimension, height: dimension)

    guard let data = rep.representation(using: .png, properties: [:]) else {
        throw NSError(domain: "icon", code: 3)
    }

    try data.write(to: url)
}

let out = URL(fileURLWithPath: CommandLine.arguments[1])
try FileManager.default.createDirectory(at: out, withIntermediateDirectories: true)

for size in [16, 32, 64, 128, 256, 512, 1024] {
    try render(size: size, to: out.appending(path: "icon_\(size).png"))
    print("renderade \(size)×\(size)")
}
