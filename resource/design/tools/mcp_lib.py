"""Helpers for scripts that drive the CorelDRAW MCP server (one MCP session per script).

The server: cd c:\\Git\\coreldraw-mcp\\server, set MCP_TRANSPORT=streamable-http, python server.py
(listens on 127.0.0.1:8765); CorelDRAW must be running.
"""
import json
import sys

from mcp import ClientSession
from mcp.client.streamable_http import streamable_http_client

URL = "http://127.0.0.1:8765/mcp"
sys.stdout.reconfigure(encoding="utf-8")


class Corel:
    def __init__(self, session: ClientSession):
        self.session = session

    async def call(self, tool: str, **args) -> dict:
        result = await self.session.call_tool(tool, args)
        text = "".join(getattr(item, "text", "") for item in result.content)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            data = {"success": False, "error": text}
        if result.is_error or not data.get("success"):
            raise RuntimeError(f"{tool}({args}) failed: {text[:500]}")
        return data


async def run(work) -> None:
    async with streamable_http_client(URL) as (read, write, *_):
        async with ClientSession(read, write) as session:
            await session.initialize()
            await work(Corel(session))
