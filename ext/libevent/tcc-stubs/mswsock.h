// Stub header for TCC — mswsock.h is needed by libevent for socket extensions.
// TCC's bundled Windows SDK lacks mswsock.h. These functions are in mswsock.dll.
#pragma once

#include <winsock2.h>

// Socket option levels/constants used by libevent (normally in mswsock.h)
#ifndef SO_UPDATE_ACCEPT_CONTEXT
#define SO_UPDATE_ACCEPT_CONTEXT   0x700B
#endif
#ifndef SO_UPDATE_CONNECT_CONTEXT
#define SO_UPDATE_CONNECT_CONTEXT  0x7010
#endif

// libevent uses AcceptEx and ConnectEx via WSAIoctl, so we just need the
// function pointer types declared. The actual GUID lookup happens at runtime.
// Minimal declarations for compilation:
__declspec(dllimport) int WINAPI WSARecvEx(SOCKET s, char *buf, int len, int *flags);
