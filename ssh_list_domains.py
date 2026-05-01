#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SSH üzerinden (çoğunlukla cPanel) kayıtlı domain listesini okur — salt okunur.

Wordlist satırı (cPanelSniper çıktısı ile uyumlu):
  host:port kullanici sifre
İlk alandaki port WHM içindir; SSH bağlantısı için varsayılan 22 kullanılır (--ssh-port).

Gereksinim:  pip install paramiko

Yalnızca yetkin olduğunuz sistemlerde kullanın.
"""

from __future__ import annotations

import argparse
import sys

REMOTE_CMD = (
    "set -e; "
    "for f in /etc/trueuserdomains /etc/userdomains /etc/domainusers; do "
    "  test -r \"$f\" && echo \"=== $f ===\" && cat \"$f\" && echo; "
    "done; "
    "if ! test -r /etc/trueuserdomains && ! test -r /etc/userdomains && ! test -r /etc/domainusers; then "
    "  echo 'UYARI: Bilinen cPanel domain dosyası okunamadı (yetki veya cPanel yok).'; "
    "fi"
)


def parse_wordlist_line(line: str):
    line = line.strip()
    if not line or line.startswith("#"):
        return None
    parts = line.split(None, 2)
    if len(parts) < 3:
        return None
    hp, user, password = parts[0], parts[1], parts[2]
    if ":" in hp:
        host, _, _port_s = hp.partition(":")
        host = host.strip()
    else:
        host = hp.strip()
    if not host:
        return None
    return host, user, password


def fetch_domains_ssh(host: str, ssh_port: int, user: str, password: str, timeout: int) -> tuple[int, str, str]:
    try:
        import paramiko
    except ImportError:
        print(
            "paramiko yüklü değil:  pip install paramiko",
            file=sys.stderr,
        )
        sys.exit(2)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        client.connect(
            hostname=host,
            port=ssh_port,
            username=user,
            password=password,
            timeout=timeout,
            allow_agent=False,
            look_for_keys=False,
        )
        stdin, stdout, stderr = client.exec_command(REMOTE_CMD, timeout=timeout)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        return code, out, err
    finally:
        client.close()


def main():
    p = argparse.ArgumentParser(description="SSH ile cPanel domain listesi dosyalarını oku")
    p.add_argument("--host", help="SSH sunucu adresi")
    p.add_argument("--user", help="SSH kullanıcı adı")
    p.add_argument("--password", help="SSH şifresi")
    p.add_argument("--ssh-port", type=int, default=22, help="SSH portu (varsayılan 22)")
    p.add_argument(
        "--wordlist",
        metavar="FILE",
        help="Satır başına: host:WHMport kullanici sifre (SSH için host kullanılır, port 22)",
    )
    p.add_argument("--timeout", type=int, default=25, help="Bağlantı/komut zaman aşımı sn")
    args = p.parse_args()

    jobs: list[tuple[str, str, str]] = []

    if args.wordlist:
        try:
            with open(args.wordlist, encoding="utf-8", errors="replace") as f:
                for line in f:
                    parsed = parse_wordlist_line(line)
                    if parsed:
                        jobs.append(parsed)
        except OSError as e:
            print(f"Wordlist okunamadı: {e}", file=sys.stderr)
            sys.exit(1)
        if not jobs:
            print("Wordlist içinde geçerli satır yok.", file=sys.stderr)
            sys.exit(1)
    elif args.host and args.user and args.password:
        jobs.append((args.host, args.user, args.password))
    else:
        p.print_help()
        sys.exit(1)

    for host, user, password in jobs:
        print(f"\n{'='*60}")
        print(f"# SSH {user}@{host}:{args.ssh_port}")
        print(f"{'='*60}")
        try:
            code, out, err = fetch_domains_ssh(
                host, args.ssh_port, user, password, args.timeout
            )
            if out.strip():
                print(out.rstrip())
            if err.strip():
                print(err.rstrip(), file=sys.stderr)
            if code != 0:
                print(f"(çıkış kodu: {code})", file=sys.stderr)
        except Exception as e:
            print(f"Hata: {e}", file=sys.stderr)


if __name__ == "__main__":
    main()
