#!/usr/bin/env python3
"""Local SMTPS test fixture, bound to loopback. NEVER for production use."""
import asyncio
import base64
import os
import ssl
import sys

HOST = "127.0.0.1"
PORT = 465  # CI runs as root for binding a privileged port.
CERT, KEY, OUTPUT = sys.argv[1:4]


async def handle(reader, writer):
    async def reply(msg):
        writer.write((msg + "\r\n").encode("ascii"))
        await writer.drain()

    await reply("220 localhost ESMTP fixture")
    authenticated = False
    rcpt = False
    data_lines = []
    try:
        while True:
            raw = await asyncio.wait_for(reader.readline(), timeout=15)
            if not raw:
                break
            line = raw.decode("ascii", errors="replace").rstrip("\r\n")
            cmd = line.split(" ", 1)[0].upper()
            if cmd in ("EHLO", "HELO"):
                await reply("250-localhost")
                await reply("250-AUTH PLAIN LOGIN")
                await reply("250 SIZE 65536")
            elif cmd == "AUTH" and line.upper().startswith("AUTH PLAIN"):
                parts = line.split(" ", 2)
                if len(parts) < 3:
                    await reply("334 ")
                    raw = await asyncio.wait_for(reader.readline(), timeout=10)
                    encoded = raw.strip()
                else:
                    encoded = parts[2].encode("ascii")
                try:
                    credentials = base64.b64decode(encoded, validate=True)
                except ValueError:
                    credentials = b""
                authenticated = (credentials == b"\x00fixture-user\x00fixture-password")
                await reply("235 2.7.0 Authenticated" if authenticated else "535 Auth rejected")
            elif cmd == "AUTH" and line.upper() == "AUTH LOGIN":
                await reply("334 VXNlcm5hbWU6")
                username = (await reader.readline()).strip()
                await reply("334 UGFzc3dvcmQ6")
                password = (await reader.readline()).strip()
                authenticated = (username == base64.b64encode(b"fixture-user")
                                 and password == base64.b64encode(b"fixture-password"))
                await reply("235 2.7.0 Authenticated" if authenticated else "535 Auth rejected")
            elif cmd == "MAIL" and authenticated:
                await reply("250 Sender OK")
            elif cmd == "RCPT" and authenticated:
                rcpt = line.upper().startswith("RCPT TO:<OWNER@EXAMPLE.COM>")
                await reply("250 Recipient OK" if rcpt else "550 Invalid recipient")
            elif cmd == "DATA" and authenticated and rcpt:
                await reply("354 End data with <CRLF>.<CRLF>")
                while True:
                    raw = await asyncio.wait_for(reader.readline(), timeout=15)
                    if not raw or raw == b".\r\n":
                        break
                    if raw.startswith(b".."):
                        raw = raw[1:]
                    data_lines.append(raw)
                if raw == b".\r\n":
                    # CI-only capture; never write SMTP secrets or reset URLs to logs.
                    with open(OUTPUT, "wb") as out:
                        out.write(b"".join(data_lines))
                    os.chmod(OUTPUT, 0o644)
                    await reply("250 Message accepted")
                else:
                    await reply("451 Incomplete message")
            elif cmd == "QUIT":
                await reply("221 Bye")
                break
            else:
                await reply("503 Invalid sequence")
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except Exception:
            pass


async def main():
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(CERT, KEY)
    server = await asyncio.start_server(handle, HOST, PORT, ssl=ctx)
    async with server:
        await server.serve_forever()


asyncio.run(main())
