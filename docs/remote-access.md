# Remote access to a shop device

Open a customer device's own web page from the CRM — fiscal printer, kiosk,
Cashmatic, POS — instead of building the NAT rule in Winbox by hand.

Every customer sits behind a MikroTik the CRM already reaches over WireGuard,
and every shop reuses the same private LAN (`192.168.100.x`), so a device is
only addressable *through its own router*. The **Remote access** tab writes the
`dst-nat` rule that publishes one device on a port of that router's VPN address.

## Using it

**Remote access → Add access**

1. **Customer** — the router (network area) the device sits behind.
2. **Device** — its devices, with their LAN IPs.
3. **Port on the router** — pre-filled with the lowest free one from 81 up.
4. **Port on the device** — 80, unless the device serves its page elsewhere.
5. **URL path** — usually blank. Cashmatic change machines answer on 80 but only
   open at `/cws/loginform.php`; picking one fills this in for you.

The form shows the exact rule before you save it. On save the CRM logs into that
router's RouterOS API and adds:

```
/ip firewall nat add chain=dstnat protocol=tcp in-interface=WIREGUARD \
    dst-port=81 action=dst-nat to-addresses=192.168.100.3 to-ports=80 \
    comment="CRM-NAT-<id> <device name>"
```

The link is then `http://<router VPN address>:<port><path>` — for a device
called Preconto behind the router at `192.168.200.11`, that is
`http://192.168.200.11:81`. It appears in the **Access** column of the Devices
tab and on the Remote access tab. **You must be on the VPN** for it to open.

The interface name comes from the router's own **VPN interface** field (Network
areas → Edit), `WIREGUARD` by default.

## What it will not do

- **Router service ports** are refused: 21, 22, 23, 53, 80, 443, 1723, 2000,
  8080, 8291 (Winbox), 8728/8729 (API), 13231, 51820. Forwarding one of these
  would redirect the router's own management traffic at a shop device and lock
  you out of the box you would need to undo it.
- **A port already in use** — by another access, or by a rule the customer built
  themselves — is refused. Two `dstnat` rules on one port means only the first
  ever fires, so a silently dead rule is worse than an error.
- **Rules that are not ours** are never touched. We only ever remove rules whose
  comment starts with `CRM-NAT-<id>`.

## When the router is unreachable

The row is saved anyway and marked **Not applied**, with the router's own error
next to it. Fix the VPN, then press **Re-apply** — it removes our old rule and
writes a fresh one, so it is safe to press at any time.

**Delete** removes the rule from the router first. If the router cannot be
reached you are asked whether to drop the CRM record regardless; the rule then
stays on the box until someone removes it by hand.

Moving an access to a device behind a *different* router is refused while the
old router is down — otherwise our rule would be stranded there with nothing
left in the CRM pointing at it.

## Where it lives

| Piece | File |
|---|---|
| Rule logic (save / apply / delete) | `src/Devices/Forwards.php` |
| RouterOS API client | `src/Devices/RouterOsApi.php` |
| Page | `views/remote_access.php` |
| Link column | `views/devices.php` |
| Endpoints | `public/device-api.php` (`save_forward`, `apply_forward`, `delete_forward`) |
| Schema | `migrations/037_device_forwards.sql` |

Admin-only: these rules change the customer's router configuration. Technical-area
users see and use the links on the Devices tab but cannot create or remove them.
