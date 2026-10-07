# RC1 licensing closure

2026-10-07. The project owner approved GPL-3.0-or-later for project-owned plugin,
service, installer/deployment and repository tooling code, and replacement of all
three inherited provenance-blocked artwork assets. Root LICENSE is the canonical
GNU GPL version3 text (verified against https://www.gnu.org/licenses/gpl-3.0.txt).
The “or later” grant is stated in SPDX headers and project documentation, not by
modifying the license text. Component LICENSE files remain canonical GPL text.

New SVG/PNG artwork is entirely geometric and newly authored for this project;
see ARTWORK-PROVENANCE.md. Prior artwork is absent from the public tree and archives.
Inherited GPL PHP copyrights remain intact; upstream helpers are not relabeled
as CodeFortex-authored. Python/runtime and project tooling receive concise SPDX
headers. Existing Moodle GPL3-or-later headers remain valid. No blanket ownership
claim over copied Moodle APIs or vendor GPL code is made.

Bundled Plyr/hls.js and mapped transitives were byte/source matched against exact
upstream packages; notices are complete and untouched. Python lock/SBOM and package
license notices are retained. See THIRD_PARTY_NOTICES.md and rc1/THIRD-PARTY-INVENTORY.json.
Moodle metadata/readme_moodle files follow the [upstream inclusion guidance](https://moodledev.io/general/community/plugincontribution/thirdpartylibraries).

No source/artwork license blocker remains under the explicit owner authorization.
This is an engineering provenance inventory, not independent legal certification
of every contributor's chain of title. The owner must select a private vulnerability
reporting contact before publication. Future binary/container-image publication
requires its own runtime source/notices review; no images or MinIO binaries are bundled.
Historical private qualification reports/manifests are preserved outside the public surface.
