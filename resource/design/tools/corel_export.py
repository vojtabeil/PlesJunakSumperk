"""Exports an edited drawing for the site: opens the .cdr in CorelDRAW and writes the SVG (via MCP).

  python corel_export.py <drawing.cdr> <export.svg>
"""
import asyncio
import sys

from mcp_lib import run


async def work(corel):
    await corel.call("open_template", path=sys.argv[1])
    await corel.call("export_svg", path=sys.argv[2], text_as_curves=False)
    print(f"exported {sys.argv[2]}")


asyncio.run(run(work))
