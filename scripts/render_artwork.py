# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Render the project's deliberately simple SVG primitives; no font/stock inputs.

Developer dependency: Pillow 12.3.0. Checked-in PNGs do not require Pillow at runtime.
"""
from pathlib import Path
import xml.etree.ElementTree as ET
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[1]
PIX = ROOT / 'plugin/mod_videolesson/pix'


def render(source, output):
    svg = ET.parse(source).getroot()
    width, height = int(svg.attrib['width']), int(svg.attrib['height'])
    scale = 4
    image = Image.new('RGBA', (width * scale, height * scale), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    for element in svg:
        kind = element.tag.rsplit('}', 1)[-1]
        attrs = element.attrib
        if kind == 'rect':
            x, y, w, h = (float(attrs[key]) * scale for key in ('x', 'y', 'width', 'height'))
            draw.rounded_rectangle((x, y, x + w, y + h), radius=float(attrs.get('rx', 0)) * scale,
                                   fill=attrs['fill'])
        elif kind == 'polygon':
            points = [tuple(float(n) * scale for n in point.split(','))
                      for point in attrs['points'].split()]
            draw.polygon(points, fill=attrs['fill'])
        elif kind != 'title':
            raise ValueError('Unsupported original design primitive')
    image.resize((width, height), Image.Resampling.LANCZOS).save(output, optimize=False)


if __name__ == '__main__':
    for name in ('monologo', 'thumbnail'):
        render(PIX / (name + '.svg'), PIX / (name + '.png'))
