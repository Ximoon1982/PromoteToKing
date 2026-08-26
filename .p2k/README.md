# P2K canonical large-file storage

The canonical **v2.10.6.23** source contains `resources/miac/seed.zip`.

GitHub's Git Blobs REST endpoint rejects that ~62 MiB file when uploaded as a
base64 JSON request. To keep the repository self-contained, the file is stored
losslessly as small binary chunks under `.p2k/large-files/`.

Restore it with:

```text
python .p2k/restore-large-files.py
```

The restore script verifies every chunk and the final file against SHA-256 before
installing it at the canonical path. The original byte-exact SOURCE ZIP is also
attached to the GitHub release as an independent recovery artifact.
