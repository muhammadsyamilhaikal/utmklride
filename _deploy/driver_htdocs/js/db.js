// =========================================================================
// db.js — IndexedDB Wrapper for UTMKL Ride Driver PWA
// Stores the last-fetched jobs/trips so the app works offline.
// Usage: await cacheSet('jobs', jobsArray);
//        const cached = await cacheGet('jobs');
// =========================================================================

const DB_NAME    = 'utmkl-driver-db';
const DB_VERSION = 1;
const STORE_NAME = 'cache';

/**
 * Opens (or upgrades) the IndexedDB database.
 * @returns {Promise<IDBDatabase>}
 */
function openDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);

        req.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'key' });
            }
        };

        req.onsuccess = (event) => resolve(event.target.result);
        req.onerror   = (event) => reject(event.target.error);
    });
}

/**
 * Saves data to the cache under a given key.
 * @param {string} key
 * @param {any} data
 * @returns {Promise<void>}
 */
async function cacheSet(key, data) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(STORE_NAME, 'readwrite');
        const req = tx.objectStore(STORE_NAME).put({ key, data, ts: Date.now() });
        tx.oncomplete = () => resolve();
        tx.onerror    = (e) => reject(e.target.error);
    });
}

/**
 * Retrieves data from the cache for a given key.
 * Returns null if the key does not exist.
 * @param {string} key
 * @returns {Promise<any|null>}
 */
async function cacheGet(key) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(STORE_NAME, 'readonly');
        const req = tx.objectStore(STORE_NAME).get(key);
        req.onsuccess = (e) => resolve(e.target.result ? e.target.result.data : null);
        req.onerror   = (e) => reject(e.target.error);
    });
}
