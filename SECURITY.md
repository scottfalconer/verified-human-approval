# Security policy

Verified Human Approval decides whether an action may run, so a flaw in it can matter more than a flaw in most libraries. Please report suspected vulnerabilities privately.

## Supported versions

This is a development preview (0.x). Only the latest commit on `main` is supported; fixes land there, with no backports. Interfaces may still change before 1.0.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting: on the repository's **Security** tab, choose **Report a vulnerability**. Please don't open a public issue or pull request for a suspected vulnerability.

Include what you can of:

- what an attacker can do, and what they need first (an account, an agent connection, database access, the signing key);
- the steps or a test that shows it, and the commit you tested;
- whether it affects the packages (`src/`), the demo application (`demo/`), or both.

This is maintained by one person. Expect an acknowledgement within a week, and an honest account of what will be fixed and when. Credit is given in the fix's notes unless you ask otherwise.

## Scope

**In scope:** the guarantees the packages claim, as described in [the security model](docs/security-model.md):

- an approval binds the exact frozen proposal and runs it once;
- authority, policy and preconditions are checked again when it runs;
- the change, the proof and the receipt commit together, or not at all;
- receipts and the signed audit log reveal later edits;
- the review pages resist the browser-side attacks that model lists.

**Also welcome:** anything in the demo that would mislead someone copying it. The demo is reference code, not a hardened product: its own security notes, and the [conformance review](docs/conformance-review.md), say what it simplifies.

**Out of scope:**

- the limits the documentation already states, such as a host that writes outside the approval service, a stolen signing key backdating records, or a compromised device or authenticator;
- denial of service through volume alone.
