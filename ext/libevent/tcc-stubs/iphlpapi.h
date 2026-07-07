// Stub header for TCC — provides minimal declarations needed by libevent.
// TCC's bundled Windows SDK lacks these headers. Functions are resolved at
// runtime via LoadLibrary/GetProcAddress (evutil_load_windows_system_library_),
// so stub declarations are sufficient for compilation.
#pragma once

#include <winsock2.h>
#include <windows.h>

// ── iphlpapi.h stubs ─────────────────────────────────────
typedef struct _IP_ADAPTER_UNICAST_ADDRESS {
    struct _IP_ADAPTER_UNICAST_ADDRESS *Next;
    ULONG                              Length;
    DWORD                              Flags;
    SOCKET_ADDRESS                     Address;
} IP_ADAPTER_UNICAST_ADDRESS, *PIP_ADAPTER_UNICAST_ADDRESS;

typedef struct _IP_ADAPTER_ADDRESSES {
    struct _IP_ADAPTER_ADDRESSES *Next;
    ULONG                          Length;
    DWORD                          IfIndex;
    LPWSTR                         AdapterName;
    PIP_ADAPTER_UNICAST_ADDRESS    FirstUnicastAddress;
} IP_ADAPTER_ADDRESSES, *PIP_ADAPTER_ADDRESSES;

// ── netioapi.h stubs ─────────────────────────────────────
// (libevent only uses IP_ADAPTER_ADDRESSES types from iphlpapi.h)

// GetAdaptersAddresses flags (from iphlpapi.h)
#define GAA_FLAG_SKIP_ANYCAST          0x0002
#define GAA_FLAG_SKIP_MULTICAST        0x0004
#define GAA_FLAG_SKIP_DNS_SERVER       0x0008
#define GAA_FLAG_INCLUDE_PREFIX        0x0010
#define GAA_FLAG_SKIP_FRIENDLY_NAME    0x0020
