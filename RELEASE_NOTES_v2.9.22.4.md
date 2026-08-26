# Promote to King v2.9.22.4

Focused Fresh Club Points Reconstruction correctness and repair update, based on frozen v2.9.22.3.

## Club reconstruction scoring

- `/pub/match/{id}` is the sole authority for the match board count.
- Only the root-level Chess.com `boards` field is accepted; lineup/player counts and alternative inferred values are never used.
- P2K/opponent scores are read directly from the two match teams.
- Finished 0–0 results are excluded as void.
- Club Points are computed only from the finished non-void result: Win = 5 × boards, Draw = 2 × boards, Loss = 0.
- Live metrics now expose total matches, valid matches, wins, draws, losses, excluded 0–0 matches and issues.

## Ready-run repair workflow

A completed staged run no longer dead-ends when issues remain. The review panel provides:

- **Recalculate staged Club data** — network-free re-normalization of already stored fresh Chess.com match payloads using the corrected endpoint-only board rule.
- **Retry match issues** — refetches only failed/unresolved match endpoints, then recalculates and returns the same run to review.
- **View match issues** — lists affected match IDs and current staged values/source flags.

Club and Player approval locks are now track-specific. Club approval becomes available as soon as Club integrity is clean, without being blocked by unrelated Player-track issues.

Existing staged reconstruction runs remain usable; no restart is required.

Core schema 15, Analytics schema 7 and the existing v2.9.22 CRON contract are unchanged.
