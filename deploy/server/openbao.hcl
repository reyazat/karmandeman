# OpenBao for workspace keys (ADR-013) on the single host. Reachable only on
# the internal `secrets` Docker network, so the listener has no TLS.
ui            = false
disable_mlock = true
api_addr      = "http://openbao:8200"

storage "file" {
  path = "/openbao/file"
}

listener "tcp" {
  address     = "0.0.0.0:8200"
  tls_disable = true
}

# The app holds one long-lived token; rotation is a manual operator step.
default_lease_ttl = "768h"
max_lease_ttl     = "87600h"
