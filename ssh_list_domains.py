#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SSH yardımcısı — cPanel domain listesi, DocumentRoot bulma ve toplu dosya deploy.

Domain DocumentRoot kaynakları (öncelik sırasına göre):
  1. /var/cpanel/userdata/USER/DOMAIN  → documentroot: satırı
  2. Apache httpd.conf → DocumentRoot direktifi
  3. /home/USER/public_html fallback

Kullanım örnekleri:
  # Tüm domainlerin dizinlerini listele:
  python ssh_list_domains.py --host 1.2.3.4 --user root --password PASS --list-roots

  # Tüm domainlerin public_html dizinine dosya yaz:
  python ssh_list_domains.py --host 1.2.3.4 --user root --password PASS \
      --deploy-to-all --filename info.php --content-file local_info.php

  # Belirli bir domaine dosya yaz:
  python ssh_list_domains.py --host 1.2.3.4 --user root --password PASS \
      --deploy-to-domain example.com --filename test.html --content "<h1>test</h1>"

Wordlist satırı (cPanelSniper çıktısı ile uyumlu):
  host:port kullanici sifre

Gereksinim:  pip install paramiko

Yalnızca yetkin olduğunuz sistemlerde kullanın.
"""

from __future__ import annotations

import argparse
import json
import posixpath
import re
import shlex
import sys
from typing import Optional

REMOTE_DISCOVER_ROOTS_CMD = r"""
set -e

declare -A domain_roots

# Yöntem 1: cPanel userdata dosyalarından DocumentRoot bul
if [ -d /var/cpanel/userdata ]; then
    for userdir in /var/cpanel/userdata/*/; do
        username=$(basename "$userdir")
        [ "$username" = "nobody" ] && continue
        [ "$username" = "." ] && continue
        for domfile in "$userdir"*; do
            [ -f "$domfile" ] || continue
            domname=$(basename "$domfile")
            # SSL ve cache dosyalarını atla
            echo "$domname" | grep -qE '\.(cache|ssl|suspended)$' && continue
            [ "$domname" = "main" ] && continue
            docroot=$(grep -m1 '^documentroot:' "$domfile" 2>/dev/null | awk '{print $2}')
            if [ -n "$docroot" ] && [ -d "$docroot" ]; then
                domain_roots["$domname"]="$docroot"
            fi
        done
    done
fi

# Yöntem 2: Apache conf'dan fallback
if [ ${#domain_roots[@]} -eq 0 ]; then
    apache_conf=""
    for cf in /usr/local/apache/conf/httpd.conf /etc/apache2/apache2.conf /etc/httpd/conf/httpd.conf; do
        [ -r "$cf" ] && apache_conf="$cf" && break
    done
    if [ -n "$apache_conf" ]; then
        current_domain=""
        while IFS= read -r line; do
            sn=$(echo "$line" | grep -oP '(?<=ServerName\s)\S+' 2>/dev/null)
            if [ -n "$sn" ]; then
                current_domain="$sn"
            fi
            dr=$(echo "$line" | grep -oP '(?<=DocumentRoot\s)\S+' 2>/dev/null | tr -d '"')
            if [ -n "$dr" ] && [ -n "$current_domain" ] && [ -d "$dr" ]; then
                domain_roots["$current_domain"]="$dr"
                current_domain=""
            fi
        done < "$apache_conf"
    fi
fi

# Yöntem 3: /home/*/public_html fallback
if [ ${#domain_roots[@]} -eq 0 ]; then
    for ph in /home/*/public_html; do
        [ -d "$ph" ] || continue
        username=$(basename "$(dirname "$ph")")
        domain_roots["$username"]="$ph"
    done
fi

# Yöntem 4: /var/cpanel/users/ dosyalarından addon domain dizinleri
if [ -d /var/cpanel/users ]; then
    for ufile in /var/cpanel/users/*; do
        [ -f "$ufile" ] || continue
        username=$(basename "$ufile")
        while IFS='=' read -r key value; do
            if echo "$key" | grep -q '^DNS'; then
                dom=$(echo "$value" | tr -d ' ')
                if [ -n "$dom" ] && [ -z "${domain_roots[$dom]+x}" ]; then
                    homedir=$(grep "^HOMEDIR=" "$ufile" 2>/dev/null | cut -d= -f2 | tr -d ' ')
                    [ -z "$homedir" ] && homedir="/home/$username"
                    if [ -d "$homedir/public_html" ]; then
                        domain_roots["$dom"]="$homedir/public_html"
                    fi
                fi
            fi
        done < "$ufile"
    done
fi

# JSON çıktı
echo "{"
first=1
for dom in "${!domain_roots[@]}"; do
    root="${domain_roots[$dom]}"
    if [ $first -eq 1 ]; then
        first=0
    else
        echo ","
    fi
    printf '  "%s": "%s"' "$dom" "$root"
done
echo ""
echo "}"
"""

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


def discover_document_roots(
    host: str, ssh_port: int, user: str, password: str, timeout: int
) -> dict[str, str]:
    """
    Sunucudaki tüm domainlerin DocumentRoot dizinlerini keşfeder.
    Dönen dict: {domain: documentroot_path}
    """
    _require_paramiko()
    client = _ssh_connect(host, ssh_port, user, password, timeout)
    try:
        stdin, stdout, stderr = client.exec_command(
            f"bash -c {shlex.quote(REMOTE_DISCOVER_ROOTS_CMD)}", timeout=timeout
        )
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()

        if code != 0:
            print(f"  [!] DocumentRoot keşfi başarısız (kod={code}): {err}", file=sys.stderr)
            return {}

        try:
            roots = json.loads(out.strip())
            return roots
        except json.JSONDecodeError:
            print(f"  [!] JSON parse hatası. Ham çıktı:\n{out}", file=sys.stderr)
            return {}
    finally:
        client.close()


def deploy_file_to_path(
    host: str,
    ssh_port: int,
    user: str,
    password: str,
    remote_path: str,
    data: bytes,
    timeout: int,
    chmod_mode: Optional[str] = None,
    mkdir: bool = True,
) -> tuple[int, str]:
    """Uzak dosya yaz. mkdir=True ise üst dizin yoksa oluşturur."""
    _require_paramiko()
    if not remote_path or remote_path.strip() != remote_path:
        raise ValueError("Geçersiz uzak yol")
    qpath = shlex.quote(remote_path)
    client = _ssh_connect(host, ssh_port, user, password, timeout)
    err_all = ""
    try:
        if mkdir:
            parent_dir = posixpath.dirname(remote_path)
            qparent = shlex.quote(parent_dir)
            _, stdout_m, stderr_m = client.exec_command(
                f"mkdir -p {qparent}", timeout=timeout
            )
            stderr_m.read()
            stdout_m.channel.recv_exit_status()

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


def deploy_to_all_roots(
    host: str,
    ssh_port: int,
    user: str,
    password: str,
    roots: dict[str, str],
    filename: str,
    data: bytes,
    timeout: int,
    chmod_mode: Optional[str] = None,
    domain_filter: Optional[str] = None,
) -> tuple[dict[str, bool], list[str]]:
    """
    Verilen domain→root dict'indeki tüm dizinlere dosya yazar.
    domain_filter verilirse sadece o domain'e yazar.
    Dönen: ({domain: başarılı_mı}, [başarılı_url_listesi])
    """
    results = {}
    success_urls: list[str] = []
    targets = roots

    if domain_filter:
        if domain_filter in roots:
            targets = {domain_filter: roots[domain_filter]}
        else:
            print(f"  [!] '{domain_filter}' bulunamadı. Mevcut domainler:", file=sys.stderr)
            for d in sorted(roots.keys()):
                print(f"      - {d}", file=sys.stderr)
            return {}, []

    for domain, root_path in sorted(targets.items()):
        remote_file = posixpath.join(root_path, filename)
        try:
            code, err = deploy_file_to_path(
                host, ssh_port, user, password,
                remote_file, data, timeout, chmod_mode
            )
            if code == 0:
                url = f"https://{domain}/{filename}"
                print(f"  [+] {domain} → {remote_file} (OK)")
                print(f"      URL: {url}")
                results[domain] = True
                success_urls.append(url)
            else:
                print(f"  [-] {domain} → {remote_file} (HATA, kod={code})")
                if err.strip():
                    print(f"      {err.strip()}", file=sys.stderr)
                results[domain] = False
        except Exception as e:
            print(f"  [-] {domain} → {remote_file} (İSTİSNA: {e})", file=sys.stderr)
            results[domain] = False

    return results, success_urls


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
    """cat > path ile yazma (eski uyumluluk)."""
    return deploy_file_to_path(
        host, ssh_port, user, password, remote_path, data, timeout, chmod_mode, mkdir=False
    )


def main():
    p = argparse.ArgumentParser(
        description="SSH: Domain DocumentRoot keşfi ve toplu dosya deploy aracı",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Örnekler:
  # Tüm domainlerin dizin yollarını listele:
  %(prog)s --host 1.2.3.4 --user root --password PASS --list-roots

  # Tüm domainlere dosya deploy et:
  %(prog)s --host 1.2.3.4 --user root --password PASS \\
      --deploy-to-all --filename ads.txt --content-file local_ads.txt

  # Tek bir domaine dosya deploy et:
  %(prog)s --host 1.2.3.4 --user root --password PASS \\
      --deploy-to-domain example.com --filename .htaccess --content-file my_htaccess

  # Wordlist ile toplu sunuculara deploy:
  %(prog)s --wordlist servers.txt --deploy-to-all --filename robots.txt --content "User-agent: *\\nAllow: /"
""",
    )

    conn = p.add_argument_group("Bağlantı")
    conn.add_argument("--host", help="SSH sunucu adresi")
    conn.add_argument("--user", help="SSH kullanıcı adı")
    conn.add_argument("--password", help="SSH şifresi")
    conn.add_argument("--ssh-port", type=int, default=22, help="SSH portu (varsayılan 22)")
    conn.add_argument("--wordlist", metavar="FILE", help="Satır başına: host:WHMport kullanici sifre")
    conn.add_argument("--timeout", type=int, default=30, help="Zaman aşımı (sn)")

    actions = p.add_argument_group("Eylemler")
    actions.add_argument(
        "--list-roots",
        action="store_true",
        help="Tüm domainlerin DocumentRoot dizinlerini listele",
    )
    actions.add_argument(
        "--deploy-to-all",
        action="store_true",
        help="Bulunan TÜM domainlerin DocumentRoot'una dosya yaz",
    )
    actions.add_argument(
        "--deploy-to-domain",
        metavar="DOMAIN",
        help="Sadece belirtilen domainin DocumentRoot'una dosya yaz",
    )
    actions.add_argument(
        "--list-domains",
        action="store_true",
        help="cPanel domain config dosyalarını göster (eski davranış)",
    )

    deploy = p.add_argument_group("Deploy ayarları")
    deploy.add_argument(
        "--filename",
        help="Hedef dosya adı (örn. ads.txt, info.php, .htaccess)",
    )
    deploy.add_argument(
        "--content-file",
        metavar="FILE",
        help="Deploy edilecek yerel dosya",
    )
    deploy.add_argument("--content", help="Deploy edilecek satır içi metin (UTF-8)")
    deploy.add_argument("--chmod", metavar="MODE", help="Yazdıktan sonra chmod (örn. 644)")
    deploy.add_argument(
        "--output",
        metavar="FILE",
        default="deploy_success.txt",
        help="Başarılı deploy URL'lerinin kaydedileceği dosya (varsayılan: deploy_success.txt)",
    )

    legacy = p.add_argument_group("Eski uyumluluk (tek dosya yazma)")
    legacy.add_argument("--write-remote", metavar="PATH", help="Tam uzak yol ile dosya yaz")
    legacy.add_argument("--write-under-home", metavar="REL_PATH", help="$HOME altına göreli dosya yaz")
    legacy.add_argument("--also-list-domains", action="store_true", help="Yazma sonrası domain listesi")

    args = p.parse_args()

    has_deploy = args.deploy_to_all or args.deploy_to_domain
    has_legacy_write = bool((args.write_remote or "").strip()) or bool((args.write_under_home or "").strip())

    if has_deploy and not args.filename:
        print("--deploy-to-all / --deploy-to-domain için --filename gerekli.", file=sys.stderr)
        sys.exit(1)

    payload = None
    if has_deploy or has_legacy_write:
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
                "Deploy/yazma için --content-file veya --content gerekli.",
                file=sys.stderr,
            )
            sys.exit(1)
        if len(payload) > 8 * 1024 * 1024:
            print("Uyarı: 8 MiB üzeri içerik; devam ediliyor.", file=sys.stderr)

    if has_legacy_write:
        write_path = (args.write_remote or "").strip()
        write_under = (args.write_under_home or "").strip()
        if write_path and write_under:
            print("--write-remote ile --write-under-home birlikte kullanılamaz.", file=sys.stderr)
            sys.exit(1)
        if write_under:
            try:
                safe_relative_under_home(write_under)
            except ValueError as e:
                print(f"--write-under-home: {e}", file=sys.stderr)
                sys.exit(1)

    no_action = not args.list_roots and not has_deploy and not args.list_domains and not has_legacy_write
    if no_action:
        p.print_help()
        sys.exit(0)

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
        print("Bağlantı bilgisi gerekli: --host/--user/--password veya --wordlist", file=sys.stderr)
        p.print_help()
        sys.exit(1)

    all_success_urls: list[str] = []

    for host, user, password in jobs:
        print(f"\n{'='*60}")
        print(f"  SSH {user}@{host}:{args.ssh_port}")
        print(f"{'='*60}")

        try:
            if args.list_roots or has_deploy:
                print("  [*] Domain DocumentRoot dizinleri keşfediliyor...")
                roots = discover_document_roots(host, args.ssh_port, user, password, args.timeout)

                if not roots:
                    print("  [!] Hiçbir DocumentRoot bulunamadı.", file=sys.stderr)
                    continue

                if args.list_roots:
                    print(f"\n  Toplam {len(roots)} domain bulundu:\n")
                    print(f"  {'Domain':<40} {'DocumentRoot'}")
                    print(f"  {'-'*40} {'-'*50}")
                    for domain, root in sorted(roots.items()):
                        print(f"  {domain:<40} {root}")
                    print()

                if has_deploy:
                    print(f"\n  [*] Dosya deploy ediliyor: {args.filename}")
                    print(f"  [*] Hedef: {'TÜM domainler' if args.deploy_to_all else args.deploy_to_domain}")
                    print()
                    results, urls = deploy_to_all_roots(
                        host, args.ssh_port, user, password,
                        roots, args.filename, payload, args.timeout,
                        chmod_mode=args.chmod,
                        domain_filter=args.deploy_to_domain,
                    )
                    all_success_urls.extend(urls)
                    success = sum(1 for v in results.values() if v)
                    fail = sum(1 for v in results.values() if not v)
                    print(f"\n  Sonuç: {success} başarılı, {fail} başarısız (toplam {len(results)})")

            if args.list_domains:
                code, out, err = fetch_domains_ssh(host, args.ssh_port, user, password, args.timeout)
                if out.strip():
                    print(out.rstrip())
                if err.strip():
                    print(err.rstrip(), file=sys.stderr)
                if code != 0:
                    print(f"(çıkış kodu: {code})", file=sys.stderr)

            if has_legacy_write:
                write_path = (args.write_remote or "").strip()
                write_under = (args.write_under_home or "").strip()
                if write_under:
                    home = fetch_remote_home(host, args.ssh_port, user, password, args.timeout)
                    target_path = resolve_under_home(home, write_under)
                else:
                    target_path = write_path
                code, err = write_remote_via_stdin(
                    host, args.ssh_port, user, password,
                    target_path, payload, args.timeout, args.chmod,
                )
                if write_under:
                    print(f"  yazıldı: {target_path}  ($HOME={home}, göreli={write_under})  (çıkış {code})")
                else:
                    print(f"  yazıldı: {target_path}  (çıkış {code})")
                if err.strip():
                    print(err.rstrip(), file=sys.stderr)
                if args.also_list_domains:
                    code2, out, err2 = fetch_domains_ssh(host, args.ssh_port, user, password, args.timeout)
                    if out.strip():
                        print(out.rstrip())
                    if err2.strip():
                        print(err2.rstrip(), file=sys.stderr)

        except Exception as e:
            print(f"  [!] Hata: {e}", file=sys.stderr)

    if all_success_urls and has_deploy:
        output_file = args.output
        try:
            with open(output_file, "a", encoding="utf-8") as f:
                for url in all_success_urls:
                    f.write(url + "\n")
            print(f"\n{'='*60}")
            print(f"  [✓] {len(all_success_urls)} başarılı URL kaydedildi: {output_file}")
            print(f"{'='*60}")
        except OSError as e:
            print(f"  [!] Çıktı dosyası yazılamadı: {e}", file=sys.stderr)


if __name__ == "__main__":
    main()
