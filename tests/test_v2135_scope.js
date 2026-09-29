const fs = require("fs");
const path = require("path");
const ROOT = path.resolve(__dirname, "..");
const read = p => fs.readFileSync(path.join(ROOT, p), "utf8");
const ok = (condition, message) => { if (!condition) throw new Error(message); };

const html = read("ChallengeListAssistant.html");
const challenge = read("assets/js/pages/challenge-list-assistant.js");
const tools = read("assets/js/admin/tool-registry.js");
const trophy = read("assets/js/admin/trophy-gallery-poc.js");
const admin = read("assets/js/admin/trophy-gallery-admin-v2121.js");

ok(read("VERSION").trim() === "2.13.5", "VERSION must be 2.13.5");

ok(html.includes('id="p2kBoardCriterionMode"'), "Board-history criterion selector missing");
ok(html.includes('<option value="average">Average boards</option>'), "Average criterion missing");
ok(html.includes('<option value="minimum_matches">Minimum number of matches</option>'), "Minimum-match criterion missing");
ok(html.includes('id="p2kMinimumMatchCount"') && html.includes('id="p2kMinimumMatchCountField" hidden'), "Minimum-match count field missing");
ok(challenge.includes('settings.criterionMode === "minimum_matches"'), "Minimum-match evaluation branch missing");
ok(challenge.includes('history.boardCounts.filter(boards => boards >= settings.minimumBoards).length'), "Qualifying-match count must use per-match board threshold");
ok(challenge.includes('qualifyingMatchCount < settings.minimumMatchCount'), "Minimum-match count threshold missing");
ok(challenge.includes('recommendationElement("p2kBoardCriterionMode").value = "average"'), "Average mode must remain the default");
ok(challenge.includes("window.P2K_API_CLIENT.processPriority"), "Shared API scheduler must remain in use");

ok(tools.includes('title: "Recruitment confidence"') && tools.includes('route: "find"'), "Recruitment confidence must open Match Assistant");
ok(tools.includes('title: "Opponent maintenance"') && tools.includes('path: "TeamPointsAdmin.html?tab=opponents"'), "Opponent maintenance route incorrect");
ok(tools.includes('title: "Achievement challenges"') && tools.includes('publicPage: "dashboard"'), "Achievement challenges must open personalized home");
ok(tools.includes('title: "Personalized authenticated home"') && tools.includes('publicPage: "dashboard"'), "Personalized home route incorrect");
ok(tools.includes('classicAdminTab: "management"'), "Tracked match data must deep-link to management");
ok(tools.includes('function publicPageHref(page, extra = {})'), "Clean public-page routing helper missing");

ok(trophy.includes("function cardMarkup(r)"), "Canonical Trophy card markup helper missing");
ok(trophy.includes("function bindCards(host){$$('[data-open]',host).forEach"), "Canonical Trophy Gallery must bind every card");
ok(trophy.includes('for(const row of $$(".p2k-trophy-meta>div",base))'), "Canonical Trophy modal cleanup must inspect every metadata row");
ok(trophy.includes("function mountPreview(host,record)"), "Canonical Trophy preview mount missing");
ok(trophy.includes("openModal(record);normalizePublicModal()"), "Preview card must use the canonical Gallery modal");
ok(admin.includes("api.mountPreview(host,previewRecord(form))"), "Admin Preview must use canonical Gallery renderer");
ok(admin.includes("This is the actual Trophy Gallery vignette. Click it to open the same Gallery modal."), "Admin Preview intent missing");

console.log("v2.13.5 focused contracts passed");
