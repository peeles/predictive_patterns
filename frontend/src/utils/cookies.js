export function getCookie(name) {
    if (typeof document === 'undefined' || !name) {
        return null
    }

    const pattern = new RegExp(`(?:^|; )${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=([^;]*)`)
    const match = document.cookie.match(pattern)

    return match ? match[1] : null
}
