#!/usr/bin/env bash
# One-time setup on the server, run by a person with sudo, from the project folder:
#   sudo bash deploy/setup-host.sh
#
# It adds the Apache site for aldervast.versyxdigital.net and gets its HTTPS certificate. Read it
# before you run it: it changes the host's Apache, which also serves other sites.
# The DNS record (a CNAME from aldervast to versyxdigital.net) must exist first, or certbot fails.
set -euo pipefail

SITE=aldervast.versyxdigital.net

cp deploy/aldervast-site.conf "/etc/apache2/sites-available/${SITE}.conf"
a2ensite "${SITE}.conf"
# Check the whole Apache configuration before reloading, so that a mistake can't take the other sites down.
apachectl configtest
systemctl reload apache2

# Gets a Let's Encrypt certificate, adds the HTTPS site, and redirects http:// to https://.
# --non-interactive: fail with a message instead of waiting for an answer. The server already has a
# Let's Encrypt account (from its other sites), so certbot needs no email address.
certbot --apache -d "${SITE}" --redirect --non-interactive --agree-tos

echo "Done. Check https://${SITE}/ in a browser."
