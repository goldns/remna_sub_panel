import logging

import httpx

from config import API_URL, DEBUG, HEADERS

log = logging.getLogger(__name__)


async def api_get(path: str, params: dict = None) -> dict:
    url = f"{API_URL.rstrip('/')}{path}"
    log.debug("API -> GET %s params=%s", url, params)
    async with httpx.AsyncClient() as client:
        resp = await client.get(url, headers=HEADERS, params=params)
    log.debug("API <- %s %s (%.0f ms)", resp.status_code, url, resp.elapsed.total_seconds() * 1000)
    if DEBUG:
        try:
            log.debug("API body: %s", str(resp.json())[:500])
        except Exception:
            log.debug("API body (raw): %s", resp.text[:500])
    resp.raise_for_status()
    return resp.json()


async def api_patch(path: str, body: dict) -> dict:
    url = f"{API_URL.rstrip('/')}{path}"
    log.debug("API -> PATCH %s body=%s", url, body)
    async with httpx.AsyncClient() as client:
        resp = await client.patch(url, headers=HEADERS, json=body)
    log.debug("API <- %s %s (%.0f ms)", resp.status_code, url, resp.elapsed.total_seconds() * 1000)
    if DEBUG:
        log.debug("API body: %s", resp.text[:500])
    resp.raise_for_status()
    return resp.json()


async def api_post(path: str, body: dict = None) -> dict:
    url = f"{API_URL.rstrip('/')}{path}"
    log.debug("API -> POST %s body=%s", url, body)
    async with httpx.AsyncClient() as client:
        resp = await client.post(url, headers=HEADERS, json=body)
    log.debug("API <- %s %s (%.0f ms)", resp.status_code, url, resp.elapsed.total_seconds() * 1000)
    if DEBUG:
        log.debug("API body: %s", resp.text[:500])
    resp.raise_for_status()
    return resp.json()


# ── users ─────────────────────────────────────────────────────────────────────

async def fetch_users():
    data = await api_get("/api/users", params={"size": 1000})
    all_users = data.get("response", {}).get("users", [])
    by_name = {u.get("username", ""): u for u in all_users}
    base_users = [u for u in all_users if not u.get("username", "").endswith("_WL")]
    return base_users, by_name


async def fetch_hwid_count(user_uuid: str) -> int:
    try:
        data = await api_get(f"/api/hwid/devices/{user_uuid}")
        return data.get("response", {}).get("total", 0)
    except Exception:
        return 0


async def load_user_with_wl(username: str):
    data = await api_get(f"/api/users/by-username/{username}")
    u = data.get("response", {})
    wl_data = None
    try:
        wl_resp = await api_get(f"/api/users/by-username/{username}_WL")
        wl_data = wl_resp.get("response")
    except Exception:
        pass
    return u, wl_data


# ── nodes ────────────────────────────────────────────────────────────────────

async def fetch_nodes_map() -> dict:
    """Returns {uuid: name} for all nodes."""
    data = await api_get("/api/nodes")
    nodes = data.get("response", [])
    return {n.get("uuid", ""): n.get("name", "—") for n in nodes}


# ── hosts ─────────────────────────────────────────────────────────────────────

async def fetch_hosts() -> list:
    data = await api_get("/api/hosts")
    return data.get("response", [])


async def fetch_config_profile_map() -> dict:
    data = await api_get("/api/config-profiles")
    profiles = data.get("response", {}).get("configProfiles", [])
    return {p.get("uuid", ""): p.get("name", "—") for p in profiles}


async def fetch_squad_inbound_map() -> dict:
    data = await api_get("/api/internal-squads")
    squads = data.get("response", {}).get("internalSquads", [])
    mapping = {}
    for s in squads:
        for inb in (s.get("inbounds") or []):
            mapping[inb.get("uuid", "")] = s.get("name", "—")
    return mapping
