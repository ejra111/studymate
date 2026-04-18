const BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:4000/api'

async function request(path, options = {}) {
  const url = `${BASE_URL}${path}`
  console.log(`Requesting: ${url}`)
  try {
    const response = await fetch(url, {
      mode: 'cors',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(options.headers || {})
      },
      ...options
    }).catch(err => {
      console.error('Fetch network error:', err)
      throw new Error(`Gagal menghubungi server di ${BASE_URL}. Pastikan backend menyala.`)
    })

    if (response.status === 204) return null

    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(data.message || 'Terjadi kesalahan pada server.')
    }

    return data
  } catch (error) {
    console.error('API Request Failed:', error)
    throw error
  }
}

export const api = {
  get: (path) => request(path),
  post: (path, body) =>
    request(path, {
      method: 'POST',
      body: JSON.stringify(body)
    }),
  put: (path, body) =>
    request(path, {
      method: 'PUT',
      body: JSON.stringify(body)
    }),
  delete: (path) =>
    request(path, {
      method: 'DELETE'
    }),
  upload: async (path, formData) => {
    const response = await fetch(`${BASE_URL}${path}`, {
      method: 'POST',
      mode: 'cors',
      headers: {
        'Accept': 'application/json'
      },
      body: formData
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(data.message || 'Gagal mengunggah berkas.')
    }
    return data
  }
}
