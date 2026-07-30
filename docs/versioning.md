# Versioned docs

The documentation is published with `mike`, the same versioning approach used
by the package's GitHub workflows.

## Published channels

| Source | Published docs version | Latest alias |
|---|---|---|
| Push to `develop` | `develop` | No |
| Stable GitHub release tag, for example `v1.0.0` | `1.0.0` | Yes |
| Prerelease GitHub release tag | tag version without `v` | No |
| Manual workflow dispatch | selected `docs_version` | Optional |

The version selector is enabled in the Material theme through:

```yaml
extra:
  version:
    provider: mike
    default: latest
    alias: true
```

## Local checks

Install the documentation dependencies:

```bash
python -m venv build/docs-venv
build/docs-venv/bin/pip install -r docs/requirements.txt
```

Build the current documentation:

```bash
build/docs-venv/bin/mkdocs build --strict
```

Preview a versioned build locally:

```bash
build/docs-venv/bin/mike deploy develop
build/docs-venv/bin/mike serve
```

## Release publishing

When a GitHub release is published, `.github/workflows/docs.yml` derives the
documentation version from the release tag. A release named `v1.0.0` therefore
publishes the docs as `1.0.0`.

Stable releases also update the `latest` alias. Prereleases publish their own
version but do not move `latest`.
