# Hall plan: how it is made

The seat picker, the administration, e-mails and tickets all use one SVG drawing of the hall. It lives in
the database (`hall_plan`, uploaded in Nebezpečná zóna -> Plánek sálu); the database keeps no coordinates,
only the ids of the shapes. This page describes how the drawing of the KKC Rapotín hall (ball 2026) was
made and how to change it.

## Files

| File | What it is |
|---|---|
| `resource/plan/KKC-Rapotin-hlavni-sal-ples-290.pdf` | Source: the organizer's plan (CAD export, 290 seats: hall 236, salonek 24, balcony 30 - the balcony is not drawn in it). |
| `resource/design/tools/` | Python scripts of the pipeline below. |
| `resource/design/hall-plan-2026.cdr` | **The drawing** (CorelDRAW). Edit this one. |
| `src/database/hall-plan.svg` | SVG export of the drawing: imported by `dev\init-db.cmd` and the portable version, uploaded to the production site in the administration. |
| `resource/design/hall-plan-2025.cdr` | The previous hall (ball 2025, other numbering), archive only. |

## Conventions of the drawing

| Shape | `id` (= object name in CorelDRAW) | Meaning |
|---|---|---|
| Group of a table (desk, chairs, captions) | `table-A1` | Table A1. The label is 1-3 capital letters and a number. |
| Chair (any shape or group) inside the table group | `seat-A1-5` | Seat 5 at table A1, label `A1/5` ("stůl A1, místo 5"). Seats are numbered from 1 at every table. |
| Desk | `table-A1-desk` | Painted by the site (navy). |
| Text on the desk (optional) | `table-A1-name` | Name of the table (white). When it only repeats the label, screen readers leave it out. |
| Text on the chair (optional) | `label-seat-A1-5` | Seat number; the seat picker colours it by the state of the seat. |
| `parket` | | Dance floor, painted by the site. |
| Anything else | any | Decoration (walls, rooms, bars, catering tables...), shown as drawn. |

Numbering used in 2026: hall A1-A9 and A12-A20 (as in the organizer's PDF, there are no A10 and A11), salonek B1-B4,
balcony C1-C5; at each table the left column of chairs top-down, then the right one (A9 lies across: the upper row, then
the lower one). Colours of seats in the drawing do not matter: the upload removes them (`HallPlanParser`), the site
paints seats by their state. The upload also removes scripts and external references.

Tables and seats are identified by their labels: uploading a changed drawing keeps the ids of the seats that stay,
seats missing from the new drawing are switched off and the reservations holding them are cancelled after a
confirmation (see the preview in the administration).

## How the 2026 drawing was made

Needs Python 3 with `pymupdf` and `mcp` (`pip install pymupdf mcp`), CorelDRAW (Technical Suite 2026) running and the
CorelDRAW MCP server (`c:\Git\coreldraw-mcp`). Scripts write to any folder (below `tmp\`), run them from the repository root.

1. **Geometry from the PDF** - tables (red rectangles, one 32 pt segment = two chairs per side) and chairs, in points of
   the page as displayed:

   ```
   python resource\design\tools\pdf_geometry.py resource\plan\KKC-Rapotin-hlavni-sal-ples-290.pdf tmp\geometry.json
   ```

2. **The SVG** - tables of the sale with numbered seats (positions from the PDF; the salonek tables and the balcony
   are placed by hand), simplified walls, rooms, dance floor, stage, bars, cloakroom and the catering tables as decoration.
   Coordinates of the walls and fittings are written in the script (`build_hall_plan.py`), read off the PDF:

   ```
   python resource\design\tools\build_hall_plan.py tmp\geometry.json tmp\plan.svg
   ```

3. **CorelDRAW** - start the MCP server (`cd c:\Git\coreldraw-mcp\server`, `set MCP_TRANSPORT=streamable-http`,
   `python server.py`), then import the SVG into a new drawing, save it and export the SVG for the site
   (CorelDRAW keeps the ids as object names and writes them back on export):

   ```
   python resource\design\tools\corel_hall_plan.py tmp\plan.svg resource\design\hall-plan-2026.cdr src\database\hall-plan.svg
   ```

4. **Check and load**: `dev\init-db.cmd` (imports `hall-plan.svg`, prints the numbers of tables and seats; 27 tables,
   290 seats) and look at the reservation page. Errors in the drawing (a seat outside its table, duplicate ids, a table
   without seats) are reported by the import and by the upload in the administration.

Steps 1-3 were run once to start the drawing. **From now on the drawing is edited in CorelDRAW**; running the generator
again would replace the hand edits.

## Changing the plan

1. Open `resource/design/hall-plan-2026.cdr` in CorelDRAW and edit it. A new chair: copy one, place it into the group of
   its table and name it `seat-<table>-<number>` (Object properties / Objects docker); a new table: a group named
   `table-<label>` with its chairs. Keep the names unique.
2. Export the SVG over `src/database/hall-plan.svg`: File -> Export -> SVG (texts as text, not curves), or with the MCP
   server running `python resource\design\tools\corel_export.py resource\design\hall-plan-2026.cdr src\database\hall-plan.svg`.
3. Locally `dev\init-db.cmd` (recreates the database with the new plan) and check the reservation page.
4. Production: Nebezpečná zóna -> Plánek sálu -> upload the SVG, check the changes and the affected reservations,
   confirm. Download the current plan first; it is the only copy in the database.
5. Commit the `.cdr` and the `.svg` together.
