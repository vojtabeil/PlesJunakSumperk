"""Step 3: the plan SVG into a new CorelDRAW drawing (.cdr) and back to the SVG for the site.

  python corel_hall_plan.py <plan.svg> <drawing.cdr> <export.svg>

Needs CorelDRAW running and the CorelDRAW MCP server (c:\\Git\\coreldraw-mcp, see mcp_lib.py) plus the
Python package mcp. CorelDRAW keeps the SVG ids as object names and writes them back as ids on export,
so the drawing can be edited by hand and exported again (corel_export.py).
"""
import asyncio
import sys

from mcp_lib import run

NAMES = ("table-A1", "table-A1-desk", "table-A1-name", "seat-A1-1", "label-seat-A1-1", "table-C5", "parket", "zdi")


async def work(corel):
    try:
        await corel.call("close_document")
    except RuntimeError:
        pass  # nothing open
    await corel.call("create_document", width=508, height=392, unit="mm")
    await corel.call("import_svg", path=sys.argv[1])
    for name in NAMES:
        found = (await corel.call("find_shape_by_name", name=name))["data"]
        if not found.get("found"):
            raise RuntimeError(f"{name} is missing after the import")
    await corel.call("save_document", path=sys.argv[2])
    await corel.call("export_svg", path=sys.argv[3], text_as_curves=False)
    print(f"saved {sys.argv[2]}, exported {sys.argv[3]}")


asyncio.run(run(work))
