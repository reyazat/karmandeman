# OpenBao for workspace keys (ADR-013) on the single host (ADR-054). Reachable
# only on the internal `secrets` Docker network. The listener still uses TLS
# from a private CA, because the app refuses plain HTTP in production.
ui            = false
disable_mlock = true
api_addr      = "https://openbao:8200"

storage "file" {
  path = "/openbao/file"
}

listener "tcp" {
  address       = "0.0.0.0:8200"
  tls_cert_file = "/openbao/tls/tls.crt"
  tls_key_file  = "/openbao/tls/tls.key"
  tls_min_version = "tls13"
}

# The app holds one long-lived token; rotation is a manual operator step.
default_lease_ttl = "768h"
max_lease_ttl     = "87600h"
