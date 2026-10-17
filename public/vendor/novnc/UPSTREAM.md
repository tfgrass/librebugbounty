Official noVNC 1.6.0 (unmodified runtime sources)
================================================

Upstream: https://github.com/novnc/noVNC
Release: https://github.com/novnc/noVNC/releases/tag/v1.6.0
Tag object: 95aaf779f7e16c5c427c68ac56976c6ae1c080f5
Commit: a8dfd6a3ea3c74244f5ebdaa5a7f1023007a7820
Archive: https://codeload.github.com/novnc/noVNC/tar.gz/refs/tags/v1.6.0
Archive SHA-256: 5066103959ef4e9b10f37e5a148627360dd8414e4cf8a7db92bdbd022e728aaa

Included: core/, vendor/pako/, AUTHORS, LICENSE.txt and the applicable full
license texts in docs/. The client entry is core/rfb.js. All module imports
resolve locally; no CDN or remote runtime assets are used.

noVNC core is MPL-2.0; bundled pako is MIT. See LICENSE.txt, AUTHORS,
docs/LICENSE.MPL-2.0, docs/LICENSE.BSD-* and vendor/pako/LICENSE. Individual
source headers retain the original notices. LBB integration lives outside
this directory in /js/studio-review-novnc.js.
