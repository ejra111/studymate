import { reactive } from 'vue'
import { api } from '../services/api'

const savedUser = JSON.parse(localStorage.getItem('studymate_user') || 'null')
const savedToken = localStorage.getItem('studymate_token')

let notificationPollTimer = null

export const state = reactive({
  user: savedUser,
  token: savedToken,
  boot: { programs: [], courses: [], locations: [] },
  dashboard: null,
  groups: [],
  matches: { partnerMatches: [], groupMatches: [], smartMatchMeta: null },
  notifications: [],
  unreadNotificationCount: 0,
  ai: {
    studyPlan: null,
    groupSummaries: {},
    groupCompatibility: {},
    coachMessages: [
      { sender: 'AI Coach', message: 'Halo! Saya Study Coach-mu. Ada yang ingin kamu tanyakan tentang kuliah hari ini?', timestamp: new Date().toISOString() }
    ],
  },
  adminSummary: { users: [], programs: [], courses: [], locations: [], activities: [] },
  loading: {
    dashboard: false,
    studyPlan: false,
    matches: false,
    groups: false,
    coach: false,
  },
  toasts: [],
})

function persistSession() {
  if (state.user && state.token) {
    localStorage.setItem('studymate_user', JSON.stringify(state.user))
    localStorage.setItem('studymate_token', state.token)
    return
  }

  localStorage.removeItem('studymate_user')
  localStorage.removeItem('studymate_token')
}

function clearSessionState() {
  state.user = null
  state.token = null
  state.dashboard = null
  state.groups = []
  state.matches = { partnerMatches: [], groupMatches: [], smartMatchMeta: null }
  state.notifications = []
  state.unreadNotificationCount = 0
  state.ai = {
    studyPlan: null,
    groupSummaries: {},
    groupCompatibility: {},
    coachMessages: [
      { sender: 'AI Coach', message: 'Halo! Saya Study Coach-mu. Ada yang ingin kamu tanyakan tentang kuliah hari ini?', timestamp: new Date().toISOString() }
    ],
  }
  state.adminSummary = { users: [], programs: [], courses: [], locations: [], activities: [] }
  stopNotificationPolling()
  persistSession()
}

export function pushToast(message, tone = 'info') {
  const id = crypto.randomUUID()
  state.toasts.push({ id, message, tone })
  setTimeout(() => {
    state.toasts = state.toasts.filter((item) => item.id !== id)
  }, 2600)
}

export async function bootstrapApp() {
  state.boot = await api.get('/bootstrap')

  if (state.user?.id) {
    await Promise.allSettled([
      loadDashboard(),
      loadGroups(),
      loadMatches(),
      loadStudyPlan(),
      loadAdminSummary(),
      loadNotifications(),
    ])
    startNotificationPolling()
  }
}

export async function login(form) {
  const payload = await api.post('/auth/login', {
    email: String(form.email || '').trim(),
    password: String(form.password || ''),
  })

  state.user = payload.user
  state.token = payload.token
  persistSession()
  await bootstrapApp()
  pushToast(`Selamat datang, ${payload.user.name}`, 'success')
  return payload
}

export async function register(form) {
  const payload = await api.post('/auth/register', {
    name: String(form.name || '').trim(),
    email: String(form.email || '').trim(),
    university: String(form.university || '').trim(),
    programName: String(form.programName || '').trim(),
    semester: Number(form.semester),
    password: String(form.password || ''),
    password_confirmation: String(form.password_confirmation || ''),
  })

  clearSessionState()
  await bootstrapApp()
  pushToast(payload.message || 'Akun berhasil dibuat. Silakan login.', 'success')
  return payload
}

export function logout() {
  clearSessionState()
  pushToast('Kamu sudah logout.', 'info')
}

export async function loadUser() {
  if (!state.user?.id) return null

  const user = await api.get(`/users/${state.user.id}`)
  state.user = { ...(state.user || {}), ...(user || {}) }
  persistSession()
  return user
}

export async function updateProfile(payload) {
  const user = await api.put(`/users/${state.user.id}`, payload)
  state.user = { ...(state.user || {}), ...(user || {}) }
  persistSession()

  // Persist schedule history to localStorage
  if (payload.availability && payload.availability.length > 0) {
    saveScheduleHistory(payload.availability)
  }

  await Promise.allSettled([loadDashboard(), loadMatches(), loadStudyPlan()])
  pushToast('Profil berhasil diperbarui.', 'success')
  return user
}

// --- Schedule History (localStorage) ---
const SCHEDULE_HISTORY_KEY = 'studymate_schedule_history'
const MAX_HISTORY_ENTRIES = 20

export function saveScheduleHistory(slots) {
  const existing = getScheduleHistory()
  // Add as a single entry (set of slots)
  const entry = { slots: [...slots].sort(), savedAt: new Date().toISOString() }
  // Check for duplicate (same slots)
  const isDuplicate = existing.some(
    (e) => JSON.stringify(e.slots) === JSON.stringify(entry.slots)
  )
  if (!isDuplicate) {
    existing.unshift(entry)
  }
  // Keep only recent entries
  const trimmed = existing.slice(0, MAX_HISTORY_ENTRIES)
  localStorage.setItem(SCHEDULE_HISTORY_KEY, JSON.stringify(trimmed))
}

export function getScheduleHistory() {
  try {
    return JSON.parse(localStorage.getItem(SCHEDULE_HISTORY_KEY) || '[]')
  } catch {
    return []
  }
}

export async function uploadAvatarFile(file) {
  const formData = new FormData()
  formData.append('avatar', file)

  const user = await api.upload(`/users/${state.user.id}/avatar`, formData)
  state.user = { ...(state.user || {}), ...(user || {}) }
  persistSession()
  pushToast('Foto profil diperbarui.', 'success')
  return user
}

export async function loadDashboard() {
  if (!state.user?.id) return null
  state.loading.dashboard = true
  try {
    state.dashboard = await api.get(`/dashboard/${state.user.id}`)
    return state.dashboard
  } catch (err) {
    console.error('Failed to load dashboard:', err)
    return null
  } finally {
    state.loading.dashboard = false
  }
}

export async function loadGroups(params = {}) {
  const query = new URLSearchParams(params).toString()
  state.groups = await api.get(`/groups${query ? `?${query}` : ''}`)
  return state.groups
}

export async function createGroup(payload) {
  await api.post('/groups', { ...payload, ownerId: state.user.id })
  await Promise.allSettled([loadGroups(), loadDashboard(), loadMatches(), loadStudyPlan(), loadAdminSummary()])
  pushToast('Grup belajar baru berhasil dibuat.', 'success')
}

export async function updateGroup(groupId, payload) {
  await api.put(`/groups/${groupId}`, { ...payload, actorId: state.user.id, actorName: state.user.name })
  await Promise.allSettled([loadGroups(), loadDashboard(), loadMatches(), loadAdminSummary()])
  pushToast('Grup berhasil diperbarui.', 'success')
}

export async function deleteGroup(groupId) {
  await api.delete(`/groups/${groupId}?actorId=${encodeURIComponent(state.user.id)}`)
  await Promise.allSettled([loadGroups(), loadDashboard(), loadMatches(), loadAdminSummary()])
  pushToast('Grup berhasil dihapus.', 'success')
}

export async function joinGroup(groupId) {
  await api.post(`/groups/${groupId}/join`, { userId: state.user.id })
  await Promise.allSettled([loadGroups(), loadDashboard(), loadMatches(), loadAdminSummary()])
  pushToast('Kamu berhasil bergabung ke grup.', 'success')
}

export async function getGoldenHour(groupId) {
  return await api.get(`/groups/${groupId}/golden-hour`)
}

export async function getGroupMessages(groupId) {
  return await api.get(`/groups/${groupId}/messages?userId=${encodeURIComponent(state.user.id)}`)
}

export async function sendGroupMessage(groupId, message) {
  return await api.post(`/groups/${groupId}/messages`, {
    userId: state.user.id,
    message: String(message || '').trim(),
  })
}

export async function getGroupSummary(groupId, force = false) {
  if (!force && state.ai.groupSummaries[groupId]) {
    return state.ai.groupSummaries[groupId]
  }

  const forceParam = force ? '&force=1' : ''
  const summary = await api.get(`/groups/${groupId}/summary?userId=${encodeURIComponent(state.user.id)}${forceParam}`)
  state.ai.groupSummaries = { ...state.ai.groupSummaries, [groupId]: summary }
  return summary
}

export async function getGroupCompatibility(groupId, force = false) {
  if (!force && state.ai.groupCompatibility[groupId]) {
    return state.ai.groupCompatibility[groupId]
  }

  const compatibility = await api.get(`/groups/${groupId}/compatibility?userId=${encodeURIComponent(state.user.id)}`)
  state.ai.groupCompatibility = { ...state.ai.groupCompatibility, [groupId]: compatibility }
  return compatibility
}

export async function loadMatches(params = {}) {
  if (!state.user?.id) return null
  state.loading.matches = true
  try {
    const query = new URLSearchParams(params).toString()
    state.matches = await api.get(`/matchmaking/${state.user.id}${query ? `?${query}` : ''}`)
    return state.matches
  } catch (err) {
    console.error('Failed to load matches:', err)
    return null
  } finally {
    state.loading.matches = false
  }
}

export async function loadStudyPlan(force = false) {
  if (!state.user?.id) return null
  if (!force && state.ai.studyPlan) return state.ai.studyPlan
  state.loading.studyPlan = true
  try {
    state.ai.studyPlan = await api.get(`/users/${state.user.id}/study-plan`)
    return state.ai.studyPlan
  } catch (err) {
    console.error('Failed to load study plan:', err)
    return null
  } finally {
    state.loading.studyPlan = false
  }
}

// --- AI Coach (with conversation history) ---
export async function askCoach(message) {
  if (!state.user?.id) return

  const userMsg = { sender: 'Me', message, timestamp: new Date().toISOString() }
  state.ai.coachMessages.push(userMsg)

  // Build conversation history (last 10 messages for context)
  const history = state.ai.coachMessages
    .filter(m => !m.isError)
    .slice(-11, -1) // exclude the just-pushed message, take up to 10 prior
    .map(m => ({
      role: m.sender === 'Me' ? 'user' : 'assistant',
      content: m.message,
    }))

  const aiResponse = await api.post(`/users/${state.user.id}/coach`, { message, history })
  state.ai.coachMessages.push(aiResponse)
  return aiResponse
}

// --- Notifications ---
export async function loadNotifications() {
  if (!state.user?.id) return null
  try {
    const result = await api.get(`/notifications/${state.user.id}`)
    state.notifications = result.notifications || []
    state.unreadNotificationCount = result.unreadCount || 0
    return result
  } catch (err) {
    console.error('Failed to load notifications:', err)
    return null
  }
}

export async function sendStudyInvite(targetUser) {
  if (!state.user?.id) return

  try {
    await api.post('/notifications', {
      senderId: state.user.id,
      receiverId: targetUser.id,
      type: 'study_invite',
      message: `${state.user.name} mengajakmu untuk belajar bersama!`,
      data: {
        senderName: state.user.name,
        senderProgram: state.user.program_name || state.user.programName || '',
      },
    })
    pushToast(`Undangan belajar berhasil dikirim ke ${targetUser.name}!`, 'success')
  } catch (err) {
    const errorMsg = err.message || 'Gagal mengirim undangan.'
    pushToast(errorMsg, 'error')
    throw err
  }
}

export async function markNotificationRead(id) {
  try {
    await api.put(`/notifications/${id}/read`)
    // Update local state
    const notif = state.notifications.find(n => n.id === id)
    if (notif) {
      notif.readAt = new Date().toISOString()
      state.unreadNotificationCount = Math.max(0, state.unreadNotificationCount - 1)
    }
  } catch (err) {
    console.error('Failed to mark notification read:', err)
  }
}

export async function markAllNotificationsRead() {
  if (!state.user?.id) return
  try {
    await api.put(`/notifications/${state.user.id}/read-all`)
    state.notifications.forEach(n => { n.readAt = n.readAt || new Date().toISOString() })
    state.unreadNotificationCount = 0
  } catch (err) {
    console.error('Failed to mark all notifications read:', err)
  }
}

export async function acceptInvite(notifId) {
  try {
    await api.post(`/notifications/${notifId}/accept`)
    pushToast('Undangan belajar diterima! Kalian sekarang berteman.', 'success')
    await Promise.allSettled([loadNotifications(), loadDashboard(), loadMatches()])
  } catch (err) {
    pushToast(err.message || 'Gagal menerima undangan.', 'error')
  }
}

export async function rejectInvite(notifId) {
  try {
    await api.post(`/notifications/${notifId}/reject`)
    pushToast('Undangan belajar ditolak.', 'info')
    await loadNotifications()
  } catch (err) {
    pushToast(err.message || 'Gagal menolak undangan.', 'error')
  }
}

function startNotificationPolling() {
  stopNotificationPolling()
  notificationPollTimer = setInterval(() => {
    loadNotifications()
  }, 15000) // Poll every 15 seconds
}

function stopNotificationPolling() {
  if (notificationPollTimer) {
    clearInterval(notificationPollTimer)
    notificationPollTimer = null
  }
}

// --- Admin ---
export async function loadAdminSummary() {
  if (state.user?.role !== 'admin') return null
  state.adminSummary = await api.get('/admin/summary')
  return state.adminSummary
}

export async function addProgram(payload) {
  await api.post('/admin/programs', { ...payload, actorId: state.user.id })
  await loadAdminSummary()
  pushToast('Program studi ditambahkan.', 'success')
}

export async function updateProgram(id, payload) {
  await api.put(`/admin/programs/${id}`, payload)
  await loadAdminSummary()
  pushToast('Program studi diperbarui.', 'success')
}

export async function deleteProgram(id) {
  await api.delete(`/admin/programs/${id}`)
  await loadAdminSummary()
  pushToast('Program studi dihapus.', 'success')
}

export async function addCourse(payload) {
  await api.post('/admin/courses', { ...payload, actorId: state.user.id })
  await loadAdminSummary()
  pushToast('Mata kuliah ditambahkan.', 'success')
}

export async function updateCourse(id, payload) {
  await api.put(`/admin/courses/${id}`, payload)
  await loadAdminSummary()
  pushToast('Mata kuliah diperbarui.', 'success')
}

export async function deleteCourse(id) {
  await api.delete(`/admin/courses/${id}`)
  await loadAdminSummary()
  pushToast('Mata kuliah dihapus.', 'success')
}

export async function addLocation(payload) {
  await api.post('/admin/locations', { ...payload, actorId: state.user.id })
  await loadAdminSummary()
  pushToast('Lokasi belajar ditambahkan.', 'success')
}

export async function updateLocation(id, payload) {
  await api.put(`/admin/locations/${id}`, payload)
  await loadAdminSummary()
  pushToast('Lokasi belajar diperbarui.', 'success')
}

export async function deleteLocation(id) {
  await api.delete(`/admin/locations/${id}`)
  await loadAdminSummary()
  pushToast('Lokasi belajar dihapus.', 'success')
}

export async function updateManagedUser(id, payload) {
  await api.put(`/admin/users/${id}`, { ...payload, actorId: state.user.id })
  await loadAdminSummary()
  pushToast('Data pengguna diperbarui.', 'success')
}
