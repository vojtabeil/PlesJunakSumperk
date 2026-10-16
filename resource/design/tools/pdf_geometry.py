"""Step 1: tables and chairs of the organizer's PDF plan (KKC Rapotin) as JSON.

  python pdf_geometry.py <plan.pdf> <geometry.json>

Needs PyMuPDF (pip install pymupdf). The PDF is a CAD export: tables are red rectangles about
21 x 32 pt (one segment = two chairs per side), chairs red outlines about 9 x 10 pt; texts are curves.
Coordinates are points of the page as displayed (the PDF page is rotated). Tables = touching segments;
chairs within 14 pt of a table belong to it, the rest are listed as "loose" (the salonek tables are
drawn grey only, so their chairs end up there).
"""
import json
import sys

import pymupdf

doc = pymupdf.open(sys.argv[1])
page = doc[0]
m = page.rotation_matrix


def red(color):
    return color and tuple(round(c, 2) for c in color) == (1.0, 0.0, 0.0)


chairs, segments = [], []
for d in page.get_drawings():
    if not red(d.get("color")) or round(d.get("width") or 0, 2) != 0.36:
        continue
    r = pymupdf.Rect(d["rect"]) * m
    r.normalize()
    w, h = r.width, r.height
    kinds = {i[0] for i in d["items"]}
    if kinds == {"re"} and 19 < min(w, h) < 23 and 30 < max(w, h) < 34:
        segments.append(r)
    elif kinds == {"l"} and 8 < max(w, h) < 11 and 8 < min(w, h) < 11:
        chairs.append(r)

tables = []
for s in sorted(segments, key=lambda r: (r.x0, r.y0)):
    for t in tables:
        if any(s.intersects(pymupdf.Rect(o.x0 - 1, o.y0 - 1, o.x1 + 1, o.y1 + 1)) for o in t):
            t.append(s)
            break
    else:
        tables.append([s])


def center(c):
    return [round((c.x0 + c.x1) / 2, 1), round((c.y0 + c.y1) / 2, 1)]


out = []
for t in tables:
    box = pymupdf.Rect(t[0])
    for s in t[1:]:
        box |= s
    area = pymupdf.Rect(box.x0 - 14, box.y0 - 14, box.x1 + 14, box.y1 + 14)
    near = [center(c) for c in chairs if area.contains(pymupdf.Point(*center(c)))]
    out.append({"box": [round(v, 1) for v in box], "segments": len(t), "chairs": near})
    print(f"table {[round(v) for v in box]}: {len(t)} segments, {len(near)} chairs")

used = {tuple(c) for o in out for c in o["chairs"]}
loose = [center(c) for c in chairs if tuple(center(c)) not in used]
print(f"{len(segments)} segments, {len(chairs)} chairs, {len(loose)} without a table")
with open(sys.argv[2], "w", encoding="utf-8") as f:
    json.dump({"tables": out, "loose": loose}, f, indent=1)
