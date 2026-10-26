---
name: review-change
description: Review Karmandeman changes for correctness, tenant safety, financial invariants and test gaps
triggers:
  - user
  - model
---

# Review a change

1. Read `docs/ENGINEERING_STANDARDS.md`, the affected PRD contracts and
   relevant existing code. Review the requested diff/files; do not assume
   this specification package has application code or a git history.
2. Prioritize exploitable trust-boundary failures, cross-workspace access,
   webhook spoofing/replay, double charging, unsafe migrations and data loss.
   Then check API/Resource contracts, Action/Repository/Service boundaries,
   Vue DTO/RTL states, n8n schema validation and missing tests.
3. For each finding, provide severity, precise file/line and a concrete
   failure scenario. Separate confirmed issues from assumptions. If no
   findings, say so and list unverified integration or production gates.
4. This is a review workflow, not an authorization to deploy, alter secrets,
   change infrastructure or modify code unless the user also requests it.
