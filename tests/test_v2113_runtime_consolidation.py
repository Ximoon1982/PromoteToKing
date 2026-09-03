from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def text(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def test_api_request_semantics_has_an_explicit_factory_boundary():
    module = text("assets/js/shared/api-request-semantics.js")
    facade = text("assets/js/shared/api-client.js")
    assert "modules.requestSemantics = Object.freeze" in module
    assert "create({ allowedOrigins })" in module
    assert "requestSemanticsFactory({ allowedOrigins: ALLOWED_ORIGINS })" in facade
    assert "normalizeUrl = semantics.normalizeUrl" in facade


def test_api_client_public_compatibility_surface_remains_owned_by_facade():
    facade = text("assets/js/shared/api-client.js")
    public = facade[facade.index("window.P2K_API_CLIENT = Object.freeze") :]
    for contract in (
        "json", "jsonDetailed", "processPriority", "prioritizeMatchReferences",
        "prioritizeRecords", "describeError", "userMessage", "isPermanent",
        "isTransient", "setConcurrentMode", "setOAuthBearerMode",
        "setConcurrency", "observeOAuthBatch", "diagnostics",
    ):
        assert contract in public


def test_v2113_parity_gate_is_anchored_and_explicit():
    gate = text("tools/test-suite/structural_parity_v2113.py")
    assert 'BASELINE = "4ececcc230ca07099b346cb47396ad00bedd5c21"' in gate
    assert '"assets/js/shared/api-client.js"' in gate
    assert '"assets/js/shared/api-request-semantics.js"' in gate
    assert 'visual_suffixes = (".css", ".png"' in gate
