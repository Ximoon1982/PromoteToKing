/* Main page and configured-club identity.
 * Edit these values to change the title, subtitle, logo, and Chess.com club used by index.html.
 * adminUsernames is an emergency fallback allow-list only. Normal administrator membership is read from the public Promote to King club API; neither path requires MariaDB.
 * Keep the logo inside this package and use a path relative to index.html.
 */
window.CLUB_SITE_BRANDING = Object.freeze({
  title: "Promote to King",
  subtitle: "Play together. Improve together. Promote to King.",
  logoPath: "assets/images/p2k-logo.jpg",
  logoAlt: "Promote to King club logo",
  clubSlug: "promote-to-king",
  clubUrl: "https://www.chess.com/club/promote-to-king",
  adminUsernames: ["Ximoon"]
});
