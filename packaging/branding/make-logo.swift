import AppKit
import Foundation

// The horizontal lockup: the mark beside the name, for the README and anywhere the product is
// introduced rather than launched. Same geometry and palette as the icon (packaging/branding).

func rgb(_ hex: UInt32) -> NSColor {
    NSColor(srgbRed: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255, blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
}

let accent = rgb(0x0F5F70)
let accentDeep = rgb(0x0A4553)
let headline = rgb(0x63BFD1)
let canvas = rgb(0xF4F4F2)

func drawMark(in rect: CGRect) {
    let radius = rect.width * 0.2237
    NSGraphicsContext.saveGraphicsState()
    NSBezierPath(roundedRect: rect, xRadius: radius, yRadius: radius).addClip()
    NSGradient(colors: [accent, accentDeep])!.draw(in: rect, angle: -90)

    let unit = rect.width / 100
    func bar(_ x: CGFloat, _ y: CGFloat, _ w: CGFloat, _ h: CGFloat) -> CGRect {
        CGRect(x: rect.minX + x * unit, y: rect.minY + (100 - y - h) * unit, width: w * unit, height: h * unit)
    }

    let stem: CGFloat = 12, arm: CGFloat = 11, left: CGFloat = 27, top: CGFloat = 23, bottom: CGFloat = 77
    canvas.setFill()
    NSBezierPath(roundedRect: bar(left, top, stem, bottom - top), xRadius: stem * 0.28, yRadius: stem * 0.28).fill()

    for (y, width, colour) in [(top, CGFloat(46), headline), (50 - arm / 2, 31, canvas), (bottom - arm, 39, canvas)] {
        colour.setFill()
        NSBezierPath(roundedRect: bar(left + stem - 1, y, width, arm), xRadius: arm * 0.28, yRadius: arm * 0.28).fill()
    }

    NSGraphicsContext.restoreGraphicsState()
}

func render(width: Int, dark: Bool, to url: URL) throws {
    let markSide = CGFloat(width) * 0.17
    let height = markSide * 1.45
    let w = CGFloat(width)

    guard let context = CGContext(data: nil, width: width, height: Int(height), bitsPerComponent: 8, bytesPerRow: 0,
                                  space: CGColorSpace(name: CGColorSpace.sRGB)!,
                                  bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue) else {
        throw NSError(domain: "logo", code: 1)
    }

    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = NSGraphicsContext(cgContext: context, flipped: false)

    let markRect = CGRect(x: markSide * 0.1, y: (height - markSide) / 2, width: markSide, height: markSide)
    drawMark(in: markRect)

    let title = "Laravel Executive AI"
    let size = markSide * 0.44
    let attributes: [NSAttributedString.Key: Any] = [
        .font: NSFont.systemFont(ofSize: size, weight: .semibold),
        .foregroundColor: dark ? rgb(0xF0F2F4) : rgb(0x15181C),
        .kern: size * 0.005,
    ]
    let text = NSAttributedString(string: title, attributes: attributes)
    let bounds = text.boundingRect(with: NSSize(width: w, height: height), options: [.usesLineFragmentOrigin])
    text.draw(at: NSPoint(x: markRect.maxX + markSide * 0.34, y: (height - bounds.height) / 2))

    NSGraphicsContext.restoreGraphicsState()

    guard let image = context.makeImage(),
          let data = NSBitmapImageRep(cgImage: image).representation(using: .png, properties: [:]) else {
        throw NSError(domain: "logo", code: 2)
    }

    try data.write(to: url)
}

let out = URL(fileURLWithPath: CommandLine.arguments[1])
try FileManager.default.createDirectory(at: out, withIntermediateDirectories: true)
try render(width: 1200, dark: false, to: out.appending(path: "logo-light.png"))
try render(width: 1200, dark: true, to: out.appending(path: "logo-dark.png"))
print("renderade logotypen i ljus och mörk variant")
