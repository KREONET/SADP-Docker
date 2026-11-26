---
type: security
breaking: true
---
Enforced the maximum API token lifetime on existing REST API and MCP tokens

- Tokens expire at the earlier of their stored expiry and the configured maximum age from issuance. Replace tokens that have already reached that age.
