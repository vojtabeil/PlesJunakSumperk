"""Step 2: the hall plan SVG of KKC Rapotin from the geometry of the organizer's PDF (pdf_geometry.py).

  python build_hall_plan.py <geometry.json> <plan.svg>

Units are PDF points of the displayed page. Conventions of the site (doc/hall-plan.md): table group
table-A1, its desk table-A1-desk and caption table-A1-name, seats seat-A1-<n> numbered from 1 per table
(left column top-down, then the right one; A9: top row, then the bottom one) with the numbers
label-seat-A1-<n>. Walls, rooms and fittings are simplified and placed by hand (coordinates read off the
PDF); the balcony is not in the PDF and is drawn below the building. No external dependencies.
"""
import json
import sys
from xml.sax.saxutils import escape

NAVY = "#2d4b8a"
WALL = "#213a6d"
TABLE_FILL = "#d7e2f1"
FLOOR = "#ffffff"
ROOM_OFF = "#e3e9f2"
PARKET = "#f6e7c8"
PODIUM = "#cfd9ea"
DECOR = "#c3ccd9"
TEXT = "#1c2b48"
MUTED = "#56627a"
FONT = "Arial"

CHAIR = 8.4  # chair square
GAP = 3.0  # chair to table

with open(sys.argv[1], encoding="utf-8") as f:
    data = json.load(f)
out = []


def add(s):
    out.append(s)


def rect(x, y, w, h, fill, rx=0, id_=None, stroke=None, sw=0, extra=""):
    attrs = f'x="{x:.1f}" y="{y:.1f}" width="{w:.1f}" height="{h:.1f}"'
    if rx:
        attrs += f' rx="{rx}" ry="{rx}"'
    style = f"fill:{fill}" + (f";stroke:{stroke};stroke-width:{sw}" if stroke else "")
    return f'<rect{f" id=\"{id_}\"" if id_ else ""} {attrs} style="{style}"{extra}/>'


def text(x, y, s, size, fill=TEXT, weight="bold", anchor="middle", id_=None, spacing=0):
    sp = f";letter-spacing:{spacing}" if spacing else ""
    return (f'<text{f" id=\"{id_}\"" if id_ else ""} x="{x:.1f}" y="{y:.1f}" style="font-family:{FONT};font-size:{size}px;'
            f'font-weight:{weight};fill:{fill};text-anchor:{anchor}{sp}">{escape(s)}</text>')


# --- Tables of the sale -------------------------------------------------------------------------
# Main hall: the PDF boxes by position; the label sits at the end of the table away from the walls' middle.
top = {346: "A8", 410: "A7", 475: "A6", 603: "A5", 667: "A4", 732: "A3", 796: "A2", 860: "A1"}
bottom = {346: "A20", 410: "A19", 475: "A18", 539: "A17", 603: "A16", 667: "A15", 732: "A14", 796: "A13", 860: "A12"}
sale = []  # (label, box [x0,y0,x1,y1], orientation, caption side)
decor_tables = []
for t in data["tables"]:
    x0, y0, x1, y1 = t["box"]
    key = round(x0)
    if y0 < 300:
        decor_tables.append(t)
    elif 480 < y0 < 490:
        sale.append(("A9", t["box"], "h", "left"))
    elif y0 < 310:
        sale.append((top[key], t["box"], "v", "top"))
    else:
        sale.append((bottom[key], t["box"], "v", "bottom"))

# Salonek: tables only drawn as grey in the PDF; boxes between the chair columns.
for label, x, y in (("B2", 618, 139), ("B1", 682, 139), ("B4", 618, 235), ("B3", 682, 235)):
    sale.append((label, [x, y, x + 21, y + 48], "v", "middle"))

# Balcony (not in the PDF): five tables of six below the building.
BAL_Y = 760
for i in range(5):
    x = 395 + i * 64
    sale.append((f"C{i + 1}", [x, BAL_Y, x + 21, BAL_Y + 48], "v", "middle"))


def seats_of(box, orientation):
    """Chair squares around a table: vertical = left column top-down, then right column top-down;
    horizontal (A9) = top row left-right, then bottom row. Rows follow the 32 pt segments (two chairs each)."""
    x0, y0, x1, y1 = box
    result = []
    if orientation == "v":
        n = round((y1 - y0) / 16)  # chairs per side
        step = (y1 - y0) / n
        for side_x in (x0 - GAP - CHAIR, x1 + GAP):
            for i in range(n):
                result.append((side_x, y0 + step * i + (step - CHAIR) / 2))
    else:
        n = round((x1 - x0) / 16)
        step = (x1 - x0) / n
        for side_y in (y0 - GAP - CHAIR, y1 + GAP):
            for i in range(n):
                result.append((x0 + step * i + (step - CHAIR) / 2, side_y))
    return result


# --- Drawing ----------------------------------------------------------------------------------------
X0, Y0, X1, Y1 = 120, 100, 1080, 840
W, H = X1 - X0, Y1 - Y0
MM = 0.3528 * 1.5  # print size: 1.5 mm per point

add(f'<svg xmlns="http://www.w3.org/2000/svg" width="{W * MM:.1f}mm" height="{H * MM:.1f}mm" viewBox="{X0} {Y0} {W} {H}">')

# Floors
add('<g id="podlahy">')
add(rect(150, 127, 360, 165, ROOM_OFF, id_="room-infocentrum"))
add(rect(510, 127, 230, 165, FLOOR, id_="room-salonek"))
add(rect(740, 127, 245, 165, FLOOR, id_="room-catering"))
add(rect(150, 292, 165, 410, FLOOR, id_="room-foyer"))
add(rect(315, 292, 740, 410, FLOOR, id_="room-sal"))
add(rect(330, 728, 360, 92, FLOOR, id_="room-balkon", rx=6))
add('</g>')

# Dance floor and stage
add('<g id="parket-podium">')
add(rect(545, 412, 355, 166, PARKET, rx=4, id_="parket"))
add(text(722, 500, "PARKET", 15, MUTED, spacing=4))
add('<path id="podium" d="M975,296 Q845,498 975,700 L1052,700 L1052,296 Z" style="fill:%s"/>' % PODIUM)
add(text(1004, 503, "PÓDIUM", 14, MUTED))
add('</g>')

# Fittings (bars, cloakroom, catering counter, catering tables as decoration)
add('<g id="vybaveni">')
for id_, x, y, w, h, label, lx, ly in (
    ("bar-2", 515, 166, 63, 21, "BAR", 546.5, 180),
    ("bar-3", 198, 409, 64, 20, "BAR", 230, 422),
    ("satna", 168, 581, 118, 16, "ŠATNA", 227, 593),
    ("catering-pult", 748, 136, 224, 11, "", 0, 0),
):
    add(rect(x, y, w, h, DECOR, rx=2, id_=id_))
    if label:
        add(text(lx, ly, label, 9, TEXT))
for i, t in enumerate(decor_tables):
    x0, y0, x1, y1 = t["box"]
    add(rect(x0, y0, x1 - x0, y1 - y0, DECOR, rx=2, id_=f"catering-stul-{i + 1}"))
    for cx, cy in t["chairs"]:
        add(rect(cx - CHAIR / 2, cy - CHAIR / 2, CHAIR, CHAIR, DECOR, rx=2))
add('</g>')

# Walls: thick lines with gaps for doors
walls = [
    (150, 127, 985, 127),  # north
    (150, 127, 150, 478), (150, 526, 150, 702),  # west (entrance)
    (150, 702, 1052, 702),  # south
    (1052, 292, 1052, 702),  # east behind the stage
    (510, 127, 510, 292),  # infocentrum | salonek
    (740, 127, 740, 192), (740, 232, 740, 292),  # salonek | catering
    (985, 127, 985, 292),  # catering east
    (150, 292, 527, 292), (570, 292, 915, 292), (955, 292, 1052, 292),  # hall north with doors
    (315, 292, 315, 445), (315, 545, 315, 702),  # foyer | hall
]
add('<g id="zdi" style="stroke:%s;stroke-width:5;stroke-linecap:square;fill:none">' % WALL)
for x1, y1, x2, y2 in walls:
    add(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}"/>')
add(rect(330, 728, 360, 92, "none", rx=6, stroke=WALL, sw=3))
add('</g>')

# Room names
add('<g id="popisky">')
for s, x, y, size, fill in (
    ("SALÓNEK", 628, 222, 12, MUTED), ("CATERING", 862, 222, 12, MUTED), ("FOYER", 232, 545, 12, MUTED),
    ("INFOCENTRUM", 330, 214, 11, "#8a94a8"), ("HLAVNÍ SÁL", 440, 545, 12, MUTED),
    ("BALKON (1. PATRO)", 510, 748, 10, MUTED),
):
    add(text(x, y, s, size, fill, spacing=1.5))
add(text(176, 505, "VCHOD", 9, TEXT, anchor="start", spacing=1))
add('<path id="vchod-sipka" d="M156,494 L172,502 L156,510 Z" style="fill:%s"/>' % "#f49d1d")
add('</g>')

# Tables with seats
add('<g id="stoly">')
count = 0
for label, box, orientation, caption in sale:
    x0, y0, x1, y1 = box
    add(f'<g id="table-{label}">')
    add(rect(x0, y0, x1 - x0, y1 - y0, TABLE_FILL, rx=3, id_=f"table-{label}-desk", stroke=NAVY, sw=0.8))
    if orientation == "h":
        tx, ty = x0 + 16, (y0 + y1) / 2 + 3.5
    elif caption == "top":
        tx, ty = (x0 + x1) / 2, y0 + 12
    elif caption == "bottom":
        tx, ty = (x0 + x1) / 2, y1 - 5
    else:
        tx, ty = (x0 + x1) / 2, (y0 + y1) / 2 + 3.5
    add(text(tx, ty, label, 9.5, NAVY, id_=f"table-{label}-name"))
    for n, (sx, sy) in enumerate(seats_of(box, orientation), start=1):
        add(rect(sx, sy, CHAIR, CHAIR, "#3678b1", rx=2, id_=f"seat-{label}-{n}"))
        add(text(sx + CHAIR / 2, sy + CHAIR / 2 + 2, str(n), 5.5, "#ffffff", id_=f"label-seat-{label}-{n}"))
        count += 1
    add('</g>')
add('</g>')
add('</svg>')

with open(sys.argv[2], "w", encoding="utf-8") as f:
    f.write("\n".join(out))
print("tables", len(sale), "seats", count)
