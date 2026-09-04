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
    assert "normalizeUrl, apiError, abortError, activityAwareOptions, timeoutError" in facade


def test_api_transport_has_an_explicit_dependency_boundary():
    module = text("assets/js/shared/api-transport.js")
    facade = text("assets/js/shared/api-client.js")
    assert "modules.transport = Object.freeze" in module
    assert "nativeFetch, defaultTimeoutMs, jsonpEnabled, counters, integer" in module
    assert "const { executeFetch, fetchSnapshot } = transportFactory" in facade


def test_api_request_coordinator_has_an_explicit_dependency_boundary():
    module = text("assets/js/shared/api-request-coordinator.js")
    facade = text("assets/js/shared/api-client.js")
    assert "modules.requestCoordinator = Object.freeze" in module
    assert "defaultAttempts, defaultTimeoutMs, retryBaseDelayMs, counters, integer" in module
    assert "const { json, jsonDetailed } = requestCoordinatorFactory" in facade


def test_api_oauth_context_owns_gateway_state_and_exposes_a_snapshot():
    module = text("assets/js/shared/api-oauth-context.js")
    facade = text("assets/js/shared/api-client.js")
    assert "modules.oauthContext = Object.freeze" in module
    assert "const oauthGatewayQueue = []" in module
    assert "function adaptOAuthGateway(batch)" in module
    assert "function diagnostics()" in module
    assert "const oauthContext = oauthContextFactory" in facade


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


def test_recruitment_uses_read_only_admin_job_adapter_without_new_policy():
    reader = text("server/team-points/src/AdminJob/RecruitmentRunStateReader.php")
    runner = text("server/team-points/src/AdminJob/JobRunner.php")
    endpoint = text("server/team-points/public/recruitment-admin.php")
    assert "implements JobStateReader" in reader
    assert "new JobRunner(new RecruitmentRunStateReader" in endpoint
    assert "'total' => (int)($job['total']" in endpoint
    assert "'checked' => (int)($job['completed']" in endpoint
    assert "'pending' => (int)($job['checkpoint_backlog']" in endpoint
    assert "function run" not in runner and "function schedule" not in runner
    assert "function save" not in reader


def test_v2113_parity_gate_is_anchored_and_explicit():
    gate = text("tools/test-suite/structural_parity_v2113.py")
    assert 'BASELINE = "4ececcc230ca07099b346cb47396ad00bedd5c21"' in gate
    assert '"assets/js/shared/api-client.js"' in gate
    assert '"assets/js/shared/api-request-semantics.js"' in gate
    assert '"assets/js/shared/api-request-coordinator.js"' in gate
    assert '"assets/js/shared/api-oauth-context.js"' in gate
    assert 'visual_suffixes = (".css", ".png"' in gate


def test_every_api_client_entrypoint_loads_dependencies_first():
    for html in sorted(ROOT.glob("*.htm*")):
        source = html.read_text(encoding="utf-8", errors="ignore")
        marker = "assets/js/shared/api-client.js"
        if marker not in source:
            continue
        dependency = "assets/js/shared/api-request-semantics.js"
        oauth = "assets/js/shared/api-oauth-context.js"
        transport = "assets/js/shared/api-transport.js"
        coordinator = "assets/js/shared/api-request-coordinator.js"
        assert dependency in source, html.name
        assert oauth in source, html.name
        assert transport in source, html.name
        assert coordinator in source, html.name
        assert source.index(dependency) < source.index(oauth) < source.index(transport) < source.index(coordinator) < source.index(marker), html.name


def test_v2113_dependency_inventory_covers_every_direct_loader():
    inventory = __import__("json").loads(text("tests/v2.11.3-frontend-boundaries.json"))
    expected = {
        html.name for html in ROOT.glob("*.htm*")
        if "assets/js/shared/api-client.js" in html.read_text(encoding="utf-8", errors="ignore")
    }
    assert set(inventory["entrypoints"]) == expected
    assert inventory["modules"]["shared/api-client"]["depends_on"] == ["shared/api-request-semantics", "shared/api-oauth-context", "shared/api-transport", "shared/api-request-coordinator"]


def test_async_ownership_audit_keeps_policy_with_each_feature():
    inventory = __import__("json").loads(text("tests/v2.11.3-async-ownership.json"))
    assert inventory["admin_job"] == {
        "role": "read-only normalized observation",
        "scheduler": False,
        "persistence_owner": False,
        "telemetry_adapter": "recruitment_scan",
        "execution_adoption": "deferred_no_safe_candidate",
    }
    assert inventory["runtimes"]["recruitment_scan"]["decision"] == "telemetry_adapter_only"
    retained = {
        name for name, runtime in inventory["runtimes"].items()
        if runtime["decision"] == "retain_feature_owner"
    }
    assert retained == {
        "continuous_refresh",
        "active_convergence_refresh",
        "authenticated_member_refresh",
        "analysis_coordination",
    }
    assert "universal async engine" in inventory["forbidden_changes"]


def test_v2113_architecture_documents_observable_parity_and_no_universal_engine():
    architecture = text("ARCHITECTURE_v2.11.3.md")
    consolidation = text("RUNTIME_CONSOLIDATION_v2.11.3.md")
    assert "preserving v2.11.2 UI, display, DOM, routes, payloads" in architecture
    assert "no universal async engine" in architecture
    assert "read-only `AdminJob` adapter" in consolidation
    assert "CRON entries" in consolidation
    assert "No genuinely safe bounded execution-level adoption candidate was found" in consolidation


def test_v2113_incremental_installer_covers_every_representative_211_baseline():
    builder = text("tools/release/build-v2113-runtime-package.sh")
    qualification = text("tools/release/qualify-v2113-installer.sh")
    workflow = text(".github/workflows/p2k-v2113-runtime.yml")
    instructions = text("INSTALL_v2.11.3.md")
    assert 'case "$INSTALLED" in 2.11|2.11.*)' in builder
    for revision in (
        "2ca1fc191aeef444b4886b53e25a54a83820c25c",
        "8863366bde7cb6989ce0b99aed650c3d0dfd5c01",
        "93480c852fc4c554c9a404e5d68b0ac51efed04b",
        "b8bf26c7c41ca1914323717766bca995139291aa",
        "4ececcc230ca07099b346cb47396ad00bedd5c21",
        "current-2.11.x:HEAD",
    ):
        assert revision in qualification
    assert "protected_hashes" in qualification
    assert "P2K_FORCE_INSTALL_FAILURE=1" in qualification
    assert "P2K_QUALIFICATION_CRONTAB" in qualification
    assert "build-v2113-runtime-package.sh" in workflow
    assert "qualify-v2113-installer.sh" in workflow
    assert "./install-promote-to-king-2.11.3.sh /absolute/path/to/promote-to-king" in instructions


def test_every_qualified_build_has_a_non_semantic_unique_static_asset_key():
    import importlib.util

    module_path = ROOT / "tools/release/static_asset_cache_key.py"
    spec = importlib.util.spec_from_file_location("static_asset_cache_key", module_path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    first = module.make_key("2.11.3", "a" * 40, "qualified-build-1")
    second = module.make_key("2.11.3", "a" * 40, "qualified-build-2")
    assert first != second
    assert first != "2.11.3" and second != "2.11.3"
    assert "Every qualified build must use a unique" in text("REPOSITORY_POLICY.md")
    builder = text("tools/release/build-v2113-runtime-package.sh")
    assert "STATIC_ASSET_CACHE_KEY.txt" in builder
    assert "P2K_BUILD_ID" in builder


def test_cache_key_stamps_all_local_js_and_stylesheets_and_preserves_external_urls(tmp_path):
    import importlib.util

    module_path = ROOT / "tools/release/static_asset_cache_key.py"
    spec = importlib.util.spec_from_file_location("static_asset_cache_key_fixture", module_path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    html = tmp_path / "nested" / "page.html"
    html.parent.mkdir()
    html.write_text(
        '<script src="assets/js/admin/admin-shell.js?v=2.11.2&mode=embedded#boot"></script>'
        '<script src="assets/js/admin/admin-session-controller.js"></script>'
        '<script src="assets/js/admin/tool-registry.js?v=stale"></script>'
        '<script src="assets/js/dashboard/match-assistant.js?v=2.11.2"></script>'
        '<script src="assets/js/pages/dashboard-v2.js?v=2.11.3"></script>'
        '<script src="assets/js/shared/api-client.js?v=old"></script>'
        '<link href="assets/css/site.css?theme=dark" rel="stylesheet">'
        '<script src="https://cdn.example/app.js?v=external"></script>',
        encoding="utf-8",
    )
    key = module.make_key("2.11.3", "b" * 40, "qualified-build-comprehensive")
    assert module.stamp(tmp_path, key) == 7
    assert module.verify(
        tmp_path,
        key,
        ("admin-shell.js", "admin-session-controller.js", "tool-registry.js", "match-assistant.js", "dashboard-v2.js", "api-client.js"),
    ) == 7
    stamped = html.read_text(encoding="utf-8")
    assert f"?v={key}&mode=embedded#boot" in stamped
    assert f"site.css?theme=dark&v={key}" in stamped
    assert "https://cdn.example/app.js?v=external" in stamped


def test_cache_key_verifier_rejects_missing_stale_duplicate_and_semantic_only_keys(tmp_path):
    import importlib.util
    import pytest

    module_path = ROOT / "tools/release/static_asset_cache_key.py"
    spec = importlib.util.spec_from_file_location("static_asset_cache_key_rejections", module_path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    html = tmp_path / "page.htm"
    key = module.make_key("2.11.3", "c" * 40, "qualified-build-rejections")
    for url in (
        "assets/js/app.js",
        "assets/js/app.js?v=stale",
        f"assets/js/app.js?v={key}&v={key}",
        "assets/css/site.css?v=2.11.3",
    ):
        tag = f'<link rel="stylesheet" href="{url}">' if ".css" in url else f'<script src="{url}"></script>'
        html.write_text(tag, encoding="utf-8")
        with pytest.raises(SystemExit, match="Static-asset cache-key qualification failed"):
            module.verify(tmp_path, key)


def test_frozen_release_workflows_do_not_route_later_main_prs_to_historical_gates():
    v2111 = text(".github/workflows/p2k-v2111-regression.yml")
    v2112 = text(".github/workflows/p2k-v2112-structural.yml")
    v2113 = text(".github/workflows/p2k-v2113-runtime.yml")
    assert "branches: [main, release/v2.11.1]" not in v2111
    assert "branches: [main, release/v2.11.2]" not in v2112
    assert "branches: [release/v2.11.1]" in v2111 and "workflow_dispatch:" in v2111
    assert "branches: [release/v2.11.2]" in v2112 and "workflow_dispatch:" in v2112
    assert "branches: [main, release/v2.11.3]" in v2113


def test_v2113_qualification_checks_out_and_packages_the_exact_branch_head():
    workflow = text(".github/workflows/p2k-v2113-runtime.yml")
    head_expression = "${{ github.event.pull_request.head.sha || github.sha }}"
    assert workflow.count(f"ref: {head_expression}") == 3
    assert f'test "$SOURCE_HEAD" = "{head_expression}"' in workflow
