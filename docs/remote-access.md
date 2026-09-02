# Remote access to a shop device

Open a customer device's own web page from the CRM — fiscal printer, kiosk,
Cashmatic, POS — instead of building the NAT rule in Winbox by hand.

Every customer sits behind a MikroTik the CRM already reaches over WireGuard,
and every shop reuses the same private LAN (`192.168.100.x`), so a device is
only addressable *through its own router*. The **Devices** tab does the rest.

## Using it

It lives in the device's own row on the Devices tab — there is no separate page.

- **Open :81** — opens `http://<router VPN address>:81` in a new tab. **You must
  be on the VPN**: the CRM writes the rule, your browser makes the connection.
- **×** — removes that access and deletes its rule from the router.
- **Retry** — appears instead of Open when the rule could not be written (VPN
  blip, wrong password). Hover it for the router's own message.
- **+ Access** / **Accesses** — opens the access dialog for that device.

### The access dialog

One row per access, all three values editable, plus a blank row to add another:

| Port on the router | Port on the device | Path |
|---|---|---|
| 81 | 80 | |
| 8080 | 8080 | |

- **The port is yours to choose.** The new row arrives pre-filled with the first
  port free on that router, so accepting it is still one click — but type over it
  and that port is used instead.
- **A device can hold several accesses**, one per port. That is how you reach
  more than one service on the same machine: its web page on one port, a second
  service on the next. Each has its own device-side port, so `:8080 → 8080` and
  `:81 → 80` can sit side by side on one device.
- The dialog lists what the router already uses, so you can see what to avoid.
- Leaving **Path** empty means no path — even on a Cashmatic, if you want the
  bare link.

The rule it writes:

```
/ip firewall nat add chain=dstnat protocol=tcp in-interface=WIREGUARD \
    dst-port=81 action=dst-nat to-addresses=192.168.100.3 to-ports=80 \
    comment="CRM-NAT-<id> <device name>"
```

The interface name comes from the router's own **VPN interface** field (Network
areas → Edit), `WIREGUARD` by default.

## What it works out for you

- **The port**, unless you type one. The first free from 81 up — skipping router
  service ports, ports another access already uses, and ports the customer
  forwards themselves. It reads the router's live NAT table to know the last one,
  because these boxes came with hand-made forwards (Cisbu Mugnano was already
  using 81).
- **The Cashmatic path.** A device whose name looks like a Cashmatic gets
  `/cws/loginform.php` appended, since those answer on 80 but only open there.
- **The device port**, 80 unless told otherwise.

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

`dst-port` is read as RouterOS writes it — `81`, `90,92` and `8000-8003` are all
understood, so a port inside someone else's range counts as taken. A rule on a
different interface is not in our lane and does not count; nor is a disabled one.

## When the router is unreachable

The row is saved anyway and comes back with **Retry** on it, carrying the
router's own error. Fix the VPN and press it — it removes our old rule and
writes a fresh one, so it is safe to press at any time.

**×** removes the rule from the router first. If the router cannot be reached
you are asked whether to drop the CRM record regardless; the rule then stays on
the box until someone removes it by hand.

Deleting a device takes its accesses with it, rules included.

## Calling it directly

`public/device-api.php`, admin session required:

| Action | Body |
|---|---|
| Create | `{action:"save_forward", device_id:N}` — omit `dst_port` to auto-pick, or pass one; `url_path:"-"` forces no path. Call it again with another port to give the device a second access |
| Change | `{action:"save_forward", id:N, device_id:N, dst_port:P, to_port:80, url_path:"/x"}` |
| Re-apply | `{action:"apply_forward", id:N}` |
| Remove | `{action:"delete_forward", id:N, force:0\|1}` |
| Free port | `GET ?what=suggest_port&area_id=N` → `{port, taken:[…]}` |

## Where it lives

| Piece | File |
|---|---|
| Rule logic (save / apply / delete / port choice) | `src/Devices/Forwards.php` |
| RouterOS API client | `src/Devices/RouterOsApi.php` |
| Buttons in the device row | `views/devices.php` |
| Endpoints | `public/device-api.php` |
| Schema | `migrations/037_device_forwards.sql` |

Creating and removing accesses is admin-only: these rules change the customer's
router configuration. Technical-area users see and use the **Open** links.
