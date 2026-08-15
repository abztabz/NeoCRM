# Independent Verification Report — NeoCRM 0.5.0-rc.1

- Verification ID: NEOCRM-IV-0.5.0-RC1
- Date: 2026-08-15
- Verifier: Pending assignment
- Independence declaration: Not yet available
- Candidate version/commit: 0.5.0-rc.1 / `83a757f41a2234cdecf1a8c00f95603fc274e394`
- Baseline artifact/version: NEOCRM-CONTRACT 1.0.0 candidate
- Execution mode: Production candidate
- Environment and viewports: Static build environment only
- Evidence reviewed: PHP parser run, JavaScript syntax checks, ZIP archive integrity, repository diff review, GitHub Actions `Verify NeoCRM` run 2

## Results

### Zero-tolerance checks

Static review and prior security review found no intentional browser credential exposure. Live upgrade, authorization, privacy and cloud-boundary checks remain required.

### Functional checks

GitHub Actions run 2 passed PHP 8.0 lint for every plugin PHP file, JavaScript checks, version alignment, obvious-secret scanning and ZIP archive integrity. A running WordPress instance and browser workflow were unavailable, so functional verification is blocked by missing evidence.

### Visual and responsive checks

Not independently executed against the candidate. Mobile screenshots from earlier builds are reference material, not verification evidence for this commit.

### Accessibility checks

Static implementation uses native disclosure controls, labels and focus styles. Keyboard and assistive-technology behavior remains unverified in a running WordPress environment.

### Performance and security checks

Static checks only. Cloud CRM production activation remains prohibited by the project contract.

### Known variances

- Live WordPress fresh-install test pending.
- Upgrade from the last approved installation pending.
- End-to-end visitor-to-recurring-client test pending.
- Mobile and desktop visual comparison pending.
- Independent security and privacy re-verification pending after the 0.5 changes.

### Assumptions and limitations

Builder-generated checks do not satisfy NeoOS independent verification. The installable ZIP is a candidate artifact, not an approved release baseline.

## Verdict

**Blocked by Missing Evidence**

## Conditions and reverification requirements

Run every item in `docs/release-acceptance.md` on the exact candidate commit, attach evidence, assign an independent verifier, and issue a new report. Only then may Human Authority approve and tag the release.
