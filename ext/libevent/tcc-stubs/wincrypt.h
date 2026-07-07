// Stub header for TCC — wincrypt.h is needed by arc4random.c for
// CryptGenRandom. TCC's bundled Windows SDK lacks wincrypt.h.
// CryptGenRandom is also available via advapi32.dll; we declare it here.
#pragma once

#include <windows.h>

#define PROV_RSA_FULL          1
#define CRYPT_VERIFYCONTEXT    0xF0000000

typedef ULONG_PTR HCRYPTPROV;
typedef ULONG_PTR HCRYPTKEY;

__declspec(dllimport) BOOL WINAPI CryptAcquireContextW(HCRYPTPROV *phProv,
    LPCWSTR szContainer, LPCWSTR szProvider, DWORD dwProvType, DWORD dwFlags);
__declspec(dllimport) BOOL WINAPI CryptGenRandom(HCRYPTPROV hProv,
    DWORD dwLen, BYTE *pbBuffer);
__declspec(dllimport) BOOL WINAPI CryptReleaseContext(HCRYPTPROV hProv, DWORD dwFlags);

#define CryptAcquireContext CryptAcquireContextW
