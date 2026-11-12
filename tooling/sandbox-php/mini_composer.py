#!/usr/bin/env python3
"""Sandbox mini-Composer: installs vendor/ from composer.lock using ONLY the
lock's pinned dist zipballs (codeload.github.com). No Packagist access needed.
Generates a PSR-4/PSR-0 + files autoloader and vendor/composer/installed.json
so Laravel package auto-discovery keeps working. Resumable: existing package
dirs are skipped."""

import io
import json
import os
import re
import shutil
import sys
import tempfile
import urllib.request
import zipfile
from concurrent.futures import ThreadPoolExecutor

ROOT = "/home/user/calling-agent"
LOCK = os.path.join(ROOT, "composer.lock")
VENDOR = os.path.join(ROOT, "vendor")
UA = {"User-Agent": "voca-sandbox-mini-composer"}


def download(pkg):
    name = pkg["name"]
    target = os.path.join(VENDOR, *name.split("/"))
    if os.path.isdir(target) and os.path.isfile(os.path.join(target, "composer.json")):
        return name, "cached"
    dist = (pkg.get("dist") or {}).get("url")
    if not dist:
        raise RuntimeError(f"no dist url for {name}")
    req = urllib.request.Request(dist, headers=UA)
    with urllib.request.urlopen(req, timeout=120) as resp:
        blob = resp.read()
    tmp = tempfile.mkdtemp()
    with zipfile.ZipFile(io.BytesIO(blob)) as z:
        z.extractall(tmp)
    entries = os.listdir(tmp)
    src = os.path.join(tmp, entries[0]) if len(entries) == 1 else tmp
    os.makedirs(os.path.dirname(target), exist_ok=True)
    shutil.rmtree(target, ignore_errors=True)
    shutil.move(src, target)
    shutil.rmtree(tmp, ignore_errors=True)
    return name, pkg.get("version", "?")


def main():
    lock = json.load(open(LOCK))
    packages = lock.get("packages", []) + lock.get("packages-dev", [])
    os.makedirs(VENDOR, exist_ok=True)
    results = []
    with ThreadPoolExecutor(max_workers=8) as pool:
        for name, status in pool.map(download, packages):
            results.append(name)
            print(f"[{len(results)}/{len(packages)}] {name} {status}", flush=True)
    build_autoload(packages)
    print(f"DONE. {len(results)} packages installed.")


def scan_classmap(target):
    """Small classmap builder for packages that declare classmap dirs (phpunit et al.)."""
    mapping = {}
    decl = re.compile(
        rb"^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)",
        re.M,
    )
    nested_vendor = os.path.join(target, "vendor")
    for dirpath, _dirs, files in os.walk(target):
        if dirpath.startswith(nested_vendor):
            continue
        for f in files:
            if not f.endswith((".php", ".inc")):
                continue
            path = os.path.join(dirpath, f)
            try:
                head = open(path, "rb").read(20000)
            except OSError:
                continue
            namespace_match = re.search(rb"^\s*namespace\s+([A-Za-z0-9_\x5c]+)\s*;", head, re.M)
            namespace = namespace_match.group(1).decode("latin1") + "\\" if namespace_match else ""
            for m in decl.finditer(head):
                fqcn = namespace + m.group(1).decode("latin1")
                mapping.setdefault(fqcn, path)
    return mapping


def build_autoload(packages):
    root_meta = json.load(open(os.path.join(ROOT, "composer.json")))
    psr4 = {"Tests\\\\": [os.path.join(ROOT, "tests") + "/"]}
    for prefix, d in (root_meta.get("autoload", {}).get("psr-4") or {}).items():
        key = "\\\\".join(prefix.rstrip("\\").split("\\")) + "\\\\"
        psr4[key] = [os.path.join(ROOT, d).rstrip("/") + "/"]
    psr0 = {}
    files = []
    classmap = {}
    installed = []
    for pkg in packages:
        name = pkg["name"]
        target = os.path.join(VENDOR, *name.split("/"))
        pkg_json_path = os.path.join(target, "composer.json")
        if not os.path.isfile(pkg_json_path):
            continue
        meta = json.load(open(pkg_json_path))
        autoload = meta.get("autoload") or {}
        for prefix, dirs in (autoload.get("psr-4") or {}).items():
            if not isinstance(dirs, list):
                dirs = [dirs]
            key = "\\\\".join(prefix.rstrip("\\").split("\\")) + "\\\\" if "\\" in prefix else prefix
            psr4.setdefault(key, [])
            psr4[key].extend(os.path.join(target, d).rstrip("/") + "/" for d in dirs)
        for prefix, dirs in (autoload.get("psr-0") or {}).items():
            if not isinstance(dirs, list):
                dirs = [dirs]
            psr0.setdefault(prefix, [])
            psr0[prefix].extend(os.path.join(target, d).rstrip("/") + "/" for d in dirs)
        for f in autoload.get("files") or []:
            files.append(os.path.join(target, f))
        for cm_dir in autoload.get("classmap") or []:
            classmap.update(scan_classmap(os.path.join(target, cm_dir)))
        entry = {
            "name": name,
            "version": pkg.get("version", "dev-main"),
            "pretty_version": pkg.get("version", "dev-main"),
            "version_normalized": "1.0.0.0",
            "type": meta.get("type", "library"),
            "install-path": os.path.join(*name.split("/")),
            "extra": meta.get("extra", {}),
            "autoload": autoload,
            "require": meta.get("require", {}),
        }
        installed.append(entry)

    composer_dir = os.path.join(VENDOR, "composer")
    os.makedirs(composer_dir, exist_ok=True)

    def php_array(mapping):
        lines = ["<?php", "return ["]
        for key, value in mapping.items():
            if isinstance(value, list):
                items = ", ".join("'" + v.replace("'", "\\'") + "'" for v in value)
                lines.append(f"    '{key}' => [{items}],")
            else:
                lines.append(f"    '{key}' => '{value.replace(chr(39), chr(92)+chr(39))}',")
        lines.append("];\n")
        return "\n".join(lines)

    with open(os.path.join(composer_dir, "autoload_psr4.php"), "w") as fh:
        fh.write(php_array(psr4))
    with open(os.path.join(composer_dir, "autoload_psr0.php"), "w") as fh:
        fh.write(php_array(psr0))
    with open(os.path.join(composer_dir, "autoload_classmap.php"), "w") as fh:
        fh.write(php_array(classmap))
    with open(os.path.join(composer_dir, "autoload_files.php"), "w") as fh:
        fh.write(php_array({str(i): path for i, path in enumerate(files)}))
    with open(os.path.join(composer_dir, "installed.json"), "w") as fh:
        json.dump({"packages": installed, "dev": True, "dev-package-names": []}, fh)

    with open(os.path.join(VENDOR, "autoload.php"), "w") as fh:
        fh.write("""<?php
// Sandbox mini-composer autoloader (composer.lock-pinned vendor tree).
if (!class_exists('Composer\\Autoload\\ClassLoader')) {
    // Framework code type-hints the real ClassLoader (e.g. Laravel's
    // Application::registerConfiguredProviders). Provide a compatible stub
    // since the sandbox autoloader below does the actual mapping.
    eval('namespace Composer\\Autoload; class ClassLoader {'
        .'public $sandboxClassMap=[];'
        .'public function addPsr4($p,$d){}'
        .'public function add($p,$d){}'
        .'public function addClassMap(array $m){$this->sandboxClassMap += $m;}'
        .'public function getClassMap(){return $this->sandboxClassMap;}'
        .'public function set($p,$d){}'
        .'public function getPrefixesPsr4(){return [];}'
        .'public function setClassMapAuthoritative($v){}'
        .'public function setApcuPrefix($p){}'
        .'public function register($p=false){'
        .    'self::$registeredLoaders[__DIR__]=$this;}' // vendor dir, like real Composer
        .'public function unregister(){unset(self::$registeredLoaders[__DIR__]);}'
        .'private static $registeredLoaders=[];'
        .'public static function getRegisteredLoaders(){return self::$registeredLoaders;}}');
}
$GLOBALS[\'__sandbox_class_loader_stub\'] = new \\Composer\\Autoload\\ClassLoader();
$GLOBALS[\'__sandbox_class_loader_stub\']->addClassMap(require __DIR__.\'/composer/autoload_classmap.php\');
$GLOBALS[\'__sandbox_class_loader_stub\']->register();
$psr4 = require __DIR__.'/composer/autoload_psr4.php';
$psr0 = require __DIR__.'/composer/autoload_psr0.php';
$classmap = require __DIR__.'/composer/autoload_classmap.php';
spl_autoload_register(function ($class) use ($psr4, $psr0, $classmap) {
    if (isset($classmap[$class])) { require $classmap[$class]; return; }
    foreach ($psr4 as $prefix => $dirs) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $rel = str_replace('\\\\', '/', substr($class, strlen($prefix))).'.php';
            foreach ($dirs as $dir) {
                $file = $dir.$rel;
                if (is_file($file)) { require $file; return; }
            }
        }
    }
    foreach ($psr0 as $prefix => $dirs) {
        if ($prefix === '' || strncmp($class, $prefix, strlen($prefix)) === 0) {
            $rel = str_replace(['\\\\', '_'], '/', $class).'.php';
            foreach ($dirs as $dir) {
                $file = $dir.$rel;
                if (is_file($file)) { require $file; return; }
            }
        }
    }
});
foreach (require __DIR__.'/composer/autoload_files.php' as $bootstrapFile) {
    require_once $bootstrapFile;
}
""")
    print(f"autoload: {len(psr4)} psr4, {len(psr0)} psr0, {len(files)} files, {len(classmap)} classmap entries")


if __name__ == "__main__":
    sys.exit(main())
