# Policy bundle history

- `pilot-2026-10-27-v3`: current candidate; owner's final seven-day complete
  erasure deadline overrides the ordinary fourteen-day backup maximum age.
  Private-data encryption and the account deletion button remain requirements.
  Other retention proposals, final review and evidence
  remain pending. See `docs/adr/ADR-011-seven-day-complete-erasure.md`.
- `pilot-2026-10-27-v2`: archived, unapproved candidate. Its fourteen-day erasure
  deadline was superseded by the owner's final seven-day choice in v3; it must
  not be activated. The ordinary fourteen-day backup maximum age still applies.
- `pilot-2026-10-27-v1`: archived, unapproved proposal. Its ninety-day backup
  and ninety-seven-day erasure proposal was superseded by v2. It must not be
  activated. It is retained only to explain the earlier decision.

Changing the selected candidate does not approve it or implement retention,
encryption or erasure. Policy checks must remain fail-closed until approval
and real implementation evidence are recorded.
