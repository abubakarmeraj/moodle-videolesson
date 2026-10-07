# Third-party notices

Project-owned code and original CodeFortex artwork are GPL-3.0-or-later. This
does not relicense upstream code, libraries, services or operating systems.
Original BitKea Technologies LLP / MooPlugins PHP copyrights and GPL3-or-later
grants remain in the inherited Moodle plugin. Moodle itself is not bundled.

## Bundled JavaScript

| Library | Version / upstream | License / modification | Bundled location |
|---|---|---|---|
| Plyr | 3.7.8, github.com/sampotts/plyr; Sam Potts | MIT; JS/CSS upstream bytes, extra min CSS whitespace-normalized | resources/plyr (plugin) |
| hls.js | 1.6.2, github.com/video-dev/hls.js; video-dev/Dailymotion, derived Brightcove attribution retained | Apache2.0; unmodified | resources/hls.min.js (plugin) |
| rangetouch | 2.0.1, Sam Potts | MIT; unmodified mapped source | Plyr bundle |
| loadjs | 4.2.0, Andres Morey | MIT; unmodified mapped source | Plyr bundle |
| custom-event-polyfill | 1.0.7, Evan Krambuhl | MIT; unmodified mapped source | Plyr polyfilled bundle |
| url-polyfill | 1.1.12, Valentin Richard | MIT; unmodified mapped source | Plyr polyfilled bundle |
| eventemitter3 | 5.0.1, Arnout Kazemier | MIT; unmodified mapped source | hls.js bundle |
| url-toolkit | 2.2.5, Tom Jenkinson | Apache2.0; unmodified mapped source | hls.js bundle |
| @svta/common-media-library | 0.10.0, Streaming Video Technology Alliance | Apache2.0; unmodified mapped source | hls.js bundle |

Canonical notices are under `docs/licenses/javascript/`,
`docs/licenses/Plyr-3.7.8-MIT.txt`, `docs/licenses/hls.js-1.6.2-Apache-2.0.txt` and
`docs/licenses/Apache-2.0.txt`. The plugin distribution also includes adjacent
LICENSE/readme_moodle files and `thirdpartylibs.xml`. `custom.css` is a plugin
override, not an upstream Plyr work. No bundled fonts or stock artwork.

Exact upstream sources, source maps and build correspondence are in versioned
npm archives: https://registry.npmjs.org/plyr/-/plyr-3.7.8.tgz and
https://registry.npmjs.org/hls.js/-/hls.js-1.6.2.tgz. The public dependency inventory
records all seven mapped transitive packages with exact source matches. Core-js
appears in upstream package metadata but is not claimed as a shipped mapped module.
No player dependency version/bytes were changed in release preparation.

## Python dependencies

Locked requirements and `service/video-service/release/python-sbom.json` identify
exact versions/distribution hashes. Python wheels are not bundled in source archives;
pip installs them at deployment. License/NOTICE/AUTHORS files are retained in
`docs/licenses/python/`; python-dateutil's full dual BSD/Apache grant and AUTHORS
are included, rather than inferred from a metadata label.

| Package | Version | License |
|---|---|---|
| annotated-doc | 0.0.5 | MIT |
| annotated-types | 0.8.0 | MIT |
| anyio | 4.15.1 | MIT |
| boto3 | 1.43.89 | Apache2.0 |
| botocore | 1.43.89 | Apache2.0 |
| click | 8.5.0 | BSD3 |
| fastapi | 0.141.1 | MIT |
| h11 | 0.16.0 | MIT |
| idna | 3.19 | BSD3 |
| jmespath | 1.1.0 | MIT |
| pydantic | 2.13.5 | MIT |
| pydantic_core | 2.46.5 | MIT |
| PyMySQL | 1.2.0 | MIT |
| python-dateutil | 2.9.0.post0 | BSD/Apache2 dual grant |
| redis (Python client) | 8.1.0 | MIT |
| s3transfer | 0.19.2 | Apache2.0 |
| six | 1.17.0 | MIT |
| starlette | 1.6.0 | BSD3 |
| typing_extensions | 4.16.0 | PSF2.0 |
| typing-inspection | 0.4.4 | MIT |
| urllib3 | 2.7.0 | MIT |
| uvicorn | 0.52.4 | BSD3 |

Developer build dependencies (Babel/Terser and transitives) are npm-locked under
scripts/amd and not bundled as node_modules. Pillow12.3.0 is used only to derive
the new PNG artwork; no Pillow binary or third-party visual input is shipped.

## Independently installed runtime

Python, Debian/Ubuntu packages, FFmpeg/libx264/AAC, Redis server, MariaDB, Nginx and
container base images retain their distribution-specific licenses. Redis client
MIT is not the server's license. Compose specifies Redis7.2; other versions need
their own review. MinIO is **not bundled**; it was a private interoperability test
server. No container images are published here.

Any future binary/image distribution needs exact runtime notice/corresponding-source
review, especially GPL-enabled FFmpeg, OS packages and version-specific Redis/S3
servers. A source-only release does not claim this work has already been completed.
See [FFmpeg legal guidance](https://www.ffmpeg.org/legal.html) and the selected
upstream distributions. Project source licensing does not override those terms.
