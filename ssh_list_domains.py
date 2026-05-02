#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SSH yardımcısı — cPanel domain listesi (salt okunur) ve isteğe bağlı uzak dosya oluşturma.

Domain listesi: /etc/trueuserdomains, /etc/userdomains, /etc/domainusers

Uzak dosya: SFTP kullanmadan tek SSH oturumunda `cat > path` ile stdin'den yazar.
  Tam yol: --write-remote PATH
  Oturum kullanıcısı $HOME + göreli yol: --write-under-home public_html/dosya.txt

Wordlist satırı (cPanelSniper çıktısı ile uyumlu):
  host:port kullanici sifre
WHM portu SSH için kullanılmaz; bağlantı --ssh-port (varsayılan 22).

Gereksinim:  pip install paramiko

Yalnızca yetkin olduğunuz sistemlerde kullanın.
"""

from __future__ import annotations

import argparse
import posixpath
import re
import shlex
import sys

REMOTE_LIST_CMD = (
    "set -e; "
    "for f in /etc/trueuserdomains /etc/userdomains /etc/domainusers; do "
    "  test -r \"$f\" && echo \"=== $f ===\" && cat \"$f\" && echo; "
    "done; "
    "if ! test -r /etc/trueuserdomains && ! test -r /etc/userdomains && ! test -r /etc/domainusers; then "
    "  echo 'UYARI: Bilinen cPanel domain dosyası okunamadı (yetki veya cPanel yok).'; "
    "fi"
)


def _require_paramiko():
    try:
        import paramiko  # noqa: F401
    except ImportError:
        print("paramiko yüklü değil:  pip install paramiko", file=sys.stderr)
        sys.exit(2)


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


def _ssh_connect(host: str, ssh_port: int, user: str, password: str, timeout: int):
    import paramiko

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        hostname=host,
        port=ssh_port,
        username=user,
        password=password,
        timeout=timeout,
        allow_agent=False,
        look_for_keys=False,
    )
    return client


def fetch_domains_ssh(host: str, ssh_port: int, user: str, password: str, timeout: int) -> tuple[int, str, str]:
    _require_paramiko()
    client = _ssh_connect(host, ssh_port, user, password, timeout)
    try:
        stdin, stdout, stderr = client.exec_command(REMOTE_LIST_CMD, timeout=timeout)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        return code, out, err
    finally:
        client.close()


def safe_relative_under_home(rel: str) -> str:
    """$HOME altı göreli yol; .. ve mutlak yol reddedilir."""
    s = rel.strip().replace("\\", "/").lstrip("/")
    if not s:
        raise ValueError("Göreli yol boş")
    if not re.match(r"^[a-zA-Z0-9][a-zA-Z0-9_./-]*$", s):
        raise ValueError(
            "Göreli yol yalnız harf, rakam, _, ., /, - içerebilir; '..' kullanılamaz"
        )
    for p in s.split("/"):
        if p == "..":
            raise ValueError("Göreli yolda '..' kullanılamaz")
    return s


def fetch_remote_home(
    host: str, ssh_port: int, user: str, password: str, timeout: int
) -> str:
    """Uzak kabukta oturum kullanıcısının $HOME dizini."""
    _require_paramiko()
    client = _ssh_connect(host, ssh_port, user, password, timeout)
    try:
        _, stdout, stderr = client.exec_command(
            "printf %s \"$HOME\"", timeout=timeout
        )
        raw = stdout.read().decode("utf-8", errors="replace").strip()
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()
        if code != 0 or not raw.startswith("/"):
            raise RuntimeError(
                f"$HOME okunamadı: {err or raw or 'boş'} (kod={code})"
            )
        return posixpath.normpath(raw)
    finally:
        client.close()


def resolve_under_home(home: str, rel: str) -> str:
    rel = safe_relative_under_home(rel)
    full = posixpath.normpath(posixpath.join(home, rel))
    home_n = posixpath.normpath(home)
    if full != home_n and not full.startswith(home_n + "/"):
        raise ValueError("Çözümlenen yol $HOME dışına taşıyor")
    return full


def write_remote_via_stdin(
    host: str,
    ssh_port: int,
    user: str,
    password: str,
    remote_path: str,
    data: bytes,
    timeout: int,
    chmod_mode: str | None,
) -> tuple[int, str]:
    """
    Uzak kabukta `cat > path`; içerik SSH stdin ile gönderilir (SFTP yok).
    """
    _require_paramiko()
    if not remote_path or remote_path.strip() != remote_path:
        raise ValueError("Geçersiz uzak yol")
    qpath = shlex.quote(remote_path)
    client = _ssh_connect(host, ssh_port, user, password, timeout)
    err_all = ""
    try:
        stdin, stdout, stderr = client.exec_command(f"cat > {qpath}", timeout=timeout)
        stdin.channel.sendall(data)
        stdin.channel.shutdown_write()
        err_b = stderr.read()
        code = stdout.channel.recv_exit_status()
        err_all += err_b.decode("utf-8", errors="replace")
        if chmod_mode:
            if not re.fullmatch(r"0?[0-7]{3,4}", chmod_mode.strip()):
                raise ValueError("--chmod için örn. 644 veya 0644")
            cm = chmod_mode.strip()
            _, stdout2, stderr2 = client.exec_command(
                f"chmod {cm} {qpath}", timeout=timeout
            )
            err_all += stderr2.read().decode("utf-8", errors="replace")
            code2 = stdout2.channel.recv_exit_status()
            if code2 != 0:
                code = code2
        return code, err_all
    finally:
        client.close()


def main():
    p = argparse.ArgumentParser(
        description="SSH: cPanel domain listesi ve/veya uzak dosya oluşturma (cat > path)"
    )
    p.add_argument("--host", help="SSH sunucu adresi")
    p.add_argument("--user", help="SSH kullanıcı adı")
    p.add_argument("--password", help="SSH şifresi")
    p.add_argument("--ssh-port", type=int, default=22, help="SSH portu (varsayılan 22)")
    p.add_argument(
        "--wordlist",
        metavar="FILE",
        help="Satır başına: host:WHMport kullanici sifre",
    )
    p.add_argument("--timeout", type=int, default=25, help="Zaman aşımı (sn)")

    p.add_argument(
        "--write-remote",
        metavar="PATH",
        help="Uzak dosyanın tam yolu; içerik --content-file veya --content ile verilir",
    )
    p.add_argument(
        "--write-under-home",
        metavar="REL_PATH",
        help=(
            "SSH oturum kullanıcısının $HOME altı göreli yol (örn. public_html/x.txt); "
            "tam uzak yol sunucuda printf \\\"\\$HOME\\\" ile tespit edilir. "
            "--write-remote ile birlikte kullanılamaz"
        ),
    )
    p.add_argument(
        "--content-file",
        metavar="FILE",
        help="Yerel dosya içeriğini uzakta oluştur",
    )
    p.add_argument("--content", help="Satır içi metin (UTF-8)")
    p.add_argument(
        "--chmod",
        metavar="MODE",
        help="Yazdıktan sonra chmod (örn. 644)",
    )
    p.add_argument(
        "--also-list-domains",
        action="store_true",
        help="Yazma (--write-remote veya --write-under-home) sonrası domain listesini de bas",
    )

    args = p.parse_args()

    write_path = (args.write_remote or "").strip()
    write_under = (args.write_under_home or "").strip()
    if write_path and write_under:
        print(
            "--write-remote ile --write-under-home birlikte kullanılamaz.",
            file=sys.stderr,
        )
        sys.exit(1)
    has_write = bool(write_path) or bool(write_under)
    if has_write:
        if args.content_file and args.content:
            print("--content-file ile --content birlikte kullanılamaz.", file=sys.stderr)
            sys.exit(1)
        if args.content_file:
            try:
                with open(args.content_file, "rb") as f:
                    payload = f.read()
            except OSError as e:
                print(f"İçerik dosyası okunamadı: {e}", file=sys.stderr)
                sys.exit(1)
        elif args.content is not None:
            payload = args.content.encode("utf-8")
        else:
            print(
                "--write-remote / --write-under-home için --content-file veya --content gerekli.",
                file=sys.stderr,
            )
            sys.exit(1)
        if write_under:
            try:
                safe_relative_under_home(write_under)
            except ValueError as e:
                print(f"--write-under-home: {e}", file=sys.stderr)
                sys.exit(1)
        if len(payload) > 8 * 1024 * 1024:
            print("Uyarı: 8 MiB üzeri içerik; devam ediliyor.", file=sys.stderr)

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
            if has_write:
                if write_under:
                    home = fetch_remote_home(
                        host, args.ssh_port, user, password, args.timeout
                    )
                    target_path = resolve_under_home(home, write_under)
                else:
                    target_path = write_path
                code, err = write_remote_via_stdin(
                    host,
                    args.ssh_port,
                    user,
                    password,
                    target_path,
                    payload,
                    args.timeout,
                    args.chmod,
                )
                if write_under:
                    print(
                        f"  yazıldı: {target_path}  ($HOME={home}, göreli={write_under})  (çıkış {code})"
                    )
                else:
                    print(f"  yazıldı: {target_path}  (çıkış {code})")
                if err.strip():
                    print(err.rstrip(), file=sys.stderr)
                if args.also_list_domains:
                    code2, out, err2 = fetch_domains_ssh(
                        host, args.ssh_port, user, password, args.timeout
                    )
                    if out.strip():
                        print(out.rstrip())
                    if err2.strip():
                        print(err2.rstrip(), file=sys.stderr)
                    if code2 != 0:
                        print(f"(liste çıkış kodu: {code2})", file=sys.stderr)
            else:
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
