<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import {
  createGroup,
  getGoldenHour,
  getGroupCompatibility,
  getGroupMessages,
  getGroupSummary,
  joinGroup,
  loadGroups,
  sendGroupMessage,
  state,
  pushToast,
} from '../store/appStore'

const filters = reactive({ search: '' })
const form = reactive({
  title: '',
  topic: '',
  description: '',
  schedule: '',
  courseName: '',
  locationName: '',
  capacity: 5,
})

const scheduleError = ref('')
const selectedGroupId = ref('')
const detailPanel = ref(null)
const chatMessages = ref([])
const chatDraft = ref('')
const summary = ref(null)
const compatibility = ref(null)
const goldenHour = ref(null)
const loadingSummary = ref(false)
const refreshingSummary = ref(false)

onMounted(async () => {
  await loadGroups()
})

const groups = computed(() => state.groups || [])

function isMember(group) {
  return (group.memberIds || group.members?.map((member) => member.id) || []).includes(state.user?.id)
}

async function searchGroups() {
  await loadGroups({ search: filters.search })
}

// Auto-reset when search is cleared
watch(
  () => filters.search,
  (newVal) => {
    if (newVal === '' || newVal === null) {
      loadGroups()
    }
  }
)

// === Auto UPPERCASE handler ===
function autoUpperCase(field) {
  form[field] = String(form[field] || '').toUpperCase()
}

// === Schedule validation & normalization (Hari + HH:mm) ===
const VALID_DAYS = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU']

const DAY_ALIASES = {
  'SEN': 'SENIN', 'SEL': 'SELASA', 'RAB': 'RABU', 'KAM': 'KAMIS',
  'JUM': 'JUMAT', "JUM'AT": 'JUMAT', 'SAB': 'SABTU', 'MIN': 'MINGGU', 'MING': 'MINGGU',
  'MONDAY': 'SENIN', 'TUESDAY': 'SELASA', 'WEDNESDAY': 'RABU',
  'THURSDAY': 'KAMIS', 'FRIDAY': 'JUMAT', 'SATURDAY': 'SABTU', 'SUNDAY': 'MINGGU',
}

function normalizeSchedule(raw) {
  let text = String(raw || '').trim().toUpperCase()

  // Remove punctuation like commas or dots that might be typed
  text = text.replace(/[,.]/g, ' ')

  // Remove filler words
  text = text.replace(/\bJAM\b/g, '').replace(/\bPUKUL\b/g, '').replace(/\bWIB\b/g, '').trim()

  // Handle "pagi/siang/sore/malam" time words
  text = text.replace(/(\d{1,2})\s*(PAGI)/g, (_, h) => {
    const hour = parseInt(h)
    return String(hour).padStart(2, '0') + ':00'
  })
  text = text.replace(/(\d{1,2})\s*(SIANG)/g, (_, h) => {
    let hour = parseInt(h)
    if (hour < 10) hour += 12
    return String(hour).padStart(2, '0') + ':00'
  })
  text = text.replace(/(\d{1,2})\s*(SORE)/g, (_, h) => {
    let hour = parseInt(h)
    if (hour < 12) hour += 12
    return String(Math.min(hour, 23)).padStart(2, '0') + ':00'
  })
  text = text.replace(/(\d{1,2})\s*(MALAM)/g, (_, h) => {
    let hour = parseInt(h)
    if (hour < 12) hour += 12
    return String(Math.min(hour, 23)).padStart(2, '0') + ':00'
  })

  // Normalize bare hour "7" → "07:00"
  text = text.replace(/\b(\d{1,2})\b(?!:|\d)/g, (_, h) => {
    return String(parseInt(h)).padStart(2, '0') + ':00'
  })

  // Clean up extra spaces
  text = text.replace(/\s+/g, ' ').trim()

  // Extract day and time
  const parts = text.split(/\s+/)
  let day = null
  let time = null

  for (const part of parts) {
    const cleanPart = part.replace(/[^A-Z0-9:]/g, '')
    if (VALID_DAYS.includes(cleanPart)) {
      day = cleanPart
    } else if (DAY_ALIASES[cleanPart]) {
      day = DAY_ALIASES[cleanPart]
    } else if (/^\d{1,2}:\d{2}$/.test(cleanPart)) {
      time = cleanPart
    } else if (/^\d{4}$/.test(cleanPart)) {
      // Handle "1900" -> "19:00"
      time = cleanPart.slice(0, 2) + ':' + cleanPart.slice(2)
    }
  }

  if (day && time) {
    // Validate time range
    const [hh, mm] = time.split(':').map(Number)
    if (hh >= 0 && hh <= 23 && mm >= 0 && mm <= 59) {
      return `${day} ${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`
    }
  }

  // If we couldn't normalize but it's already in a decent format, try to be lenient
  if (day && !time) {
     // If only day is found, maybe time is missing? Keep it for now but it won't pass validation
  }

  return null // invalid
}

function validateSchedule() {
  if (!form.schedule) {
    scheduleError.value = ''
    return true
  }

  const result = normalizeSchedule(form.schedule)
  if (result) {
    form.schedule = result
    scheduleError.value = ''
    return true
  }

  scheduleError.value = 'Format harus: HARI HH:mm (contoh: SENIN 19:00)'
  return false
}

async function submitGroup() {
  if (!validateSchedule()) {
    pushToast('Format jadwal tidak valid. Contoh: SENIN 19:00', 'error')
    return
  }

  try {
    await createGroup({
      title: form.title.toUpperCase(),
      topic: form.topic.toUpperCase(),
      description: form.description.toUpperCase(),
      schedule: form.schedule.toUpperCase(),
      courseName: form.courseName.toUpperCase(),
      locationName: form.locationName.toUpperCase(),
      capacity: form.capacity,
    })
    Object.assign(form, {
      title: '',
      topic: '',
      description: '',
      schedule: '',
      courseName: '',
      locationName: '',
      capacity: 5,
    })
    scheduleError.value = ''
  } catch (error) {
    console.error(error)
  }
}

async function openRoom(group) {
  selectedGroupId.value = group.id
  loadingSummary.value = true
  
  // Berikan waktu untuk Vue merender elemen v-if
  setTimeout(() => {
    detailPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, 100)

  try {
    // Load chat and insights
    const results = await Promise.allSettled([
      getGroupMessages(group.id),
      getGroupSummary(group.id),
      getGroupCompatibility(group.id, true),
      getGoldenHour(group.id)
    ])
    
    chatMessages.value = results[0].status === 'fulfilled' ? results[0].value : []
    summary.value = results[1].status === 'fulfilled' ? results[1].value : null
    compatibility.value = results[2].status === 'fulfilled' ? results[2].value : null
    goldenHour.value = results[3].status === 'fulfilled' ? results[3].value : null
  } catch (err) {
    console.error('Error loading room:', err)
  } finally {
    loadingSummary.value = false
  }
}

async function handleJoin(group) {
  try {
    await joinGroup(group.id)
  } catch (error) {
    console.error(error)
  }
}

async function handleSend() {
  if (!selectedGroupId.value || !chatDraft.value.trim()) return
  try {
    const message = await sendGroupMessage(selectedGroupId.value, chatDraft.value)
    chatMessages.value.push(message)
    chatDraft.value = ''
    // Auto-scroll chat to bottom
    await nextTick()
    const chatBox = document.querySelector('.chat-box')
    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight
    // Do NOT auto-refresh summary — user should click the button manually
  } catch (error) {
    console.error('Failed to send message:', error)
  }
}

async function handleRefreshSummary() {
  if (!selectedGroupId.value || refreshingSummary.value) return
  refreshingSummary.value = true
  try {
    summary.value = await getGroupSummary(selectedGroupId.value, true)
    pushToast('Rangkuman chat berhasil diperbarui!', 'success')
  } catch (err) {
    pushToast('Gagal memperbarui rangkuman.', 'error')
    console.error(err)
  } finally {
    refreshingSummary.value = false
  }
}
</script>

<template>
  <section class="groups-shell">
    <div class="grid-layout">
      <article class="panel glass-card">
        <p class="eyebrow">Buat grup belajar</p>
        <h1>Kelola grup sesuai proposal</h1>
        <div class="form-grid-inner">
          <div class="field-group">
            <label>Judul Grup</label>
            <input v-model="form.title" placeholder="Contoh: BELAJAR AI" @input="autoUpperCase('title')" />
          </div>
          <div class="field-group">
            <label>Topik</label>
            <input v-model="form.topic" placeholder="Contoh: MACHINE LEARNING" @input="autoUpperCase('topic')" />
          </div>
          <div class="field-group">
            <label>Mata Kuliah</label>
            <input v-model="form.courseName" placeholder="Contoh: KECERDASAN BUATAN" @input="autoUpperCase('courseName')" />
            <small class="hint">Ketik nama mata kuliah. Otomatis UPPERCASE.</small>
          </div>
          <div class="field-group">
            <label>Tempat Belajar</label>
            <input v-model="form.locationName" placeholder="Contoh: PERPUSTAKAAN PUSAT" @input="autoUpperCase('locationName')" />
            <small class="hint">Ketik lokasi belajar. Otomatis UPPERCASE.</small>
          </div>
          <div class="field-group">
            <label>Jadwal</label>
            <input v-model="form.schedule" placeholder="Contoh: SENIN 19:00" @blur="validateSchedule" @input="autoUpperCase('schedule')" />
            <small v-if="scheduleError" class="hint error-hint">{{ scheduleError }}</small>
            <small v-else class="hint">Format: HARI HH:mm (Senin–Minggu)</small>
          </div>
          <div class="field-group">
            <label>Kapasitas</label>
            <input v-model.number="form.capacity" type="number" min="2" placeholder="Kapasitas" />
          </div>
          <div class="field-group full-span">
            <label>Deskripsi</label>
            <textarea v-model="form.description" rows="3" placeholder="Deskripsi singkat grup" @input="autoUpperCase('description')"></textarea>
          </div>
        </div>
        <button class="primary-btn submit-btn" @click="submitGroup">Simpan Grup</button>
      </article>

      <article class="panel glass-card">
        <div class="toolbar">
          <div>
            <p class="eyebrow">Daftar grup</p>
            <h2>Cari grup yang relevan</h2>
          </div>
          <div class="search-box">
            <input v-model="filters.search" placeholder="Cari judul, topik, deskripsi" @keyup.enter="searchGroups" class="search-input" />
            <button class="primary-btn small-btn" @click="searchGroups">Cari</button>
          </div>
        </div>

        <div class="group-list" v-if="groups.length">
          <div v-for="group in groups" :key="group.id" class="group-card-item">
            <div class="group-info">
              <strong>{{ group.title }}</strong>
              <p class="group-topic">{{ group.topic }}</p>
              <div class="group-meta">
                <span>{{ group.course?.name }}</span>
                <span>{{ group.location?.name }}</span>
                <span>{{ group.schedule }}</span>
              </div>
            </div>
            <div class="card-actions">
              <span class="seat-badge">Sisa {{ group.seatsLeft ?? 0 }}</span>
              <button v-if="!isMember(group)" class="join-btn" @click="handleJoin(group)">Gabung</button>
              <button v-else class="open-btn" @click="openRoom(group)">Buka AI & Chat</button>
            </div>
          </div>
        </div>
        <p v-else class="muted-text">Belum ada grup ditemukan.</p>
      </article>
    </div>

    <!-- Detail Panel -->
    <article class="panel glass-card detail-panel-root" v-if="selectedGroupId" ref="detailPanel">
      <div v-if="loadingSummary" class="loading-overlay">
        <div class="spinner"></div>
        <p>Menyiapkan AI & Chat...</p>
      </div>

      <div class="detail-grid">
        <div class="insight-section">
          <div class="insight-header">
            <div>
              <p class="eyebrow">AI Group Insight</p>
              <h2>Golden Hour & Compatibility</h2>
            </div>
            <button
              class="refresh-summary-btn"
              :class="{ refreshing: refreshingSummary }"
              @click="handleRefreshSummary"
              :disabled="refreshingSummary"
              title="Refresh rangkuman chat"
            >
              {{ refreshingSummary ? '⏳ Memproses...' : '🔄 Refresh Rangkuman' }}
            </button>
          </div>
          <div class="insight-stack">
            <div class="insight-card-item" v-if="goldenHour">
              <div class="insight-icon">🕒</div>
              <div class="insight-content">
                <strong>{{ goldenHour.headline }}</strong>
                <p>Slot terbaik: <span>{{ goldenHour.bestSlot || 'Belum ada' }}</span> · Cakupan {{ goldenHour.coverage || 0 }}%</p>
              </div>
            </div>
            <div class="insight-card-item" v-if="compatibility">
              <div class="insight-icon">✨</div>
              <div class="insight-content">
                <strong>Smart Match Score: <span class="score-text">{{ compatibility.score }}</span></strong>
                <p>{{ compatibility.narrative }}</p>
              </div>
            </div>
            <div class="insight-card-item" v-if="summary">
              <div class="insight-icon">📝</div>
              <div class="insight-content">
                <strong>{{ summary.headline }}</strong>
                <p>{{ summary.summary }}</p>
                <div v-if="summary.source === 'groq_ai'" class="ai-source-badge">🤖 AI Groq</div>
                <div class="chip-row">
                  <span v-for="keyword in summary.keywords" :key="keyword" class="chip">{{ keyword }}</span>
                </div>
                <div v-if="summary.actionItems?.length" class="action-items">
                  <p class="action-label">📌 Tindak lanjut:</p>
                  <ul>
                    <li v-for="item in summary.actionItems" :key="item">{{ item }}</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="chat-section">
          <p class="eyebrow">Chat grup</p>
          <h2>Diskusi real-time</h2>
          <div class="chat-container">
            <div class="chat-box">
              <div v-for="message in chatMessages" :key="message.id" class="message-item" :class="{ 'own-message': message.user?.id === state.user?.id }">
                <div class="message-bubble">
                  <strong v-if="message.user?.id !== state.user?.id">{{ message.user?.name || 'User' }}</strong>
                  <p>{{ message.message }}</p>
                </div>
              </div>
            </div>
            <div class="chat-compose">
              <input v-model="chatDraft" placeholder="Ketik pesan…" @keyup.enter="handleSend" class="chat-input" />
              <button class="send-btn" @click="handleSend">Kirim</button>
            </div>
          </div>
        </div>
      </div>
    </article>
  </section>
</template>

<style scoped>
.groups-shell { display: grid; gap: 24px; }
.grid-layout { display: grid; grid-template-columns: 1fr 1.5fr; gap: 20px; }
.panel { padding: 24px; position: relative; }
h1, h2 { color: white; margin: 0; }
.eyebrow { font-size: 11px; font-weight: 600; color: #64748b; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 8px; }

.form-grid-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
.full-span { grid-column: span 2; }
.field-group { display: flex; flex-direction: column; gap: 6px; }
.field-group label { font-size: 12px; color: #94a3b8; }
.hint { font-size: 10px; color: #64748b; }
.error-hint { color: #f87171; font-weight: 600; }
.submit-btn { margin-top: 24px; width: 100%; }

.toolbar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; gap: 16px; }
.search-box { display: flex; gap: 8px; flex: 1; max-width: 400px; }
.search-input { flex: 1; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--line); color: white; padding: 10px 16px; border-radius: 12px; outline: none; }
.small-btn { padding: 10px 20px; border-radius: 12px; }

.group-list { display: grid; gap: 12px; }
.group-card-item { background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); padding: 16px; border-radius: 16px; display: flex; justify-content: space-between; align-items: center; }
.group-info strong { font-size: 16px; color: white; display: block; margin-bottom: 4px; }
.group-topic { font-size: 13px; color: #94a3b8; margin: 0 0 8px; }
.group-meta { display: flex; gap: 12px; font-size: 11px; color: #64748b; font-weight: 600; }

.card-actions { display: flex; align-items: center; gap: 12px; }
.seat-badge { font-size: 11px; font-weight: 700; color: #818cf8; background: rgba(99, 102, 241, 0.1); padding: 4px 10px; border-radius: 8px; }
.join-btn, .open-btn { padding: 8px 16px; border-radius: 10px; font-weight: 600; cursor: pointer; border: 0; transition: all 0.2s; }
.join-btn { background: rgba(255, 255, 255, 0.05); color: white; border: 1px solid var(--line); }
.open-btn { background: var(--primary); color: white; }

.detail-panel-root { margin-top: 12px; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }

.insight-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px; }
.refresh-summary-btn {
  background: rgba(99, 102, 241, 0.1);
  border: 1px solid rgba(99, 102, 241, 0.3);
  color: #818cf8;
  padding: 8px 14px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}
.refresh-summary-btn:hover:not(:disabled) { background: rgba(99, 102, 241, 0.2); }
.refresh-summary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.refresh-summary-btn.refreshing { animation: pulse-btn 1.5s ease-in-out infinite; }
@keyframes pulse-btn { 0%, 100% { opacity: 0.6; } 50% { opacity: 1; } }

.insight-stack { display: grid; gap: 16px; margin-top: 20px; }
.insight-card-item { display: flex; gap: 16px; padding: 16px; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line); border-radius: 16px; }
.insight-icon { font-size: 20px; }
.insight-content strong { display: block; color: white; margin-bottom: 4px; font-size: 14px; }
.insight-content p { font-size: 13px; color: #94a3b8; line-height: 1.5; margin: 0; }
.score-text { color: #818cf8; font-weight: 800; }
.chip-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.chip { font-size: 11px; font-weight: 700; color: #cbd5e1; background: rgba(255, 255, 255, 0.05); padding: 4px 10px; border-radius: 6px; }

.ai-source-badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  color: #a5b4fc;
  background: rgba(99, 102, 241, 0.15);
  padding: 3px 8px;
  border-radius: 6px;
  margin-top: 6px;
}

.action-items { margin-top: 10px; }
.action-label { font-size: 12px; font-weight: 700; color: #818cf8; margin: 0 0 6px; }
.action-items ul { margin: 0; padding-left: 16px; }
.action-items li { font-size: 12px; color: #94a3b8; margin-bottom: 3px; }

.chat-section { display: flex; flex-direction: column; height: 100%; }
.chat-container { display: flex; flex-direction: column; height: 450px; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--line); border-radius: 20px; margin-top: 20px; overflow: hidden; }
.chat-box { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.message-item { display: flex; flex-direction: column; }
.message-bubble { max-width: 80%; padding: 10px 16px; border-radius: 16px; background: rgba(255, 255, 255, 0.05); }
.message-bubble strong { display: block; font-size: 11px; color: #818cf8; margin-bottom: 4px; }
.message-bubble p { font-size: 14px; color: #cbd5e1; margin: 0; line-height: 1.4; }
.own-message { align-items: flex-end; }
.own-message .message-bubble { background: #312e81; border-bottom-right-radius: 4px; }

.chat-compose { padding: 16px; display: flex; gap: 8px; background: rgba(15, 23, 42, 0.4); border-top: 1px solid var(--line); }
.chat-input { flex: 1; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); color: white; padding: 10px 16px; border-radius: 12px; outline: none; font-size: 14px; }
.send-btn { background: var(--primary); color: white; border: 0; padding: 0 20px; border-radius: 12px; font-weight: 600; cursor: pointer; }

.loading-overlay { position: absolute; inset: 0; background: rgba(3, 7, 18, 0.8); z-index: 10; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; border-radius: 20px; backdrop-filter: blur(4px); }
.spinner { width: 40px; height: 40px; border: 4px solid rgba(99, 102, 241, 0.1); border-top-color: #6366f1; border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.muted-text { color: #475569; font-size: 14px; text-align: center; padding: 40px; }

@media (max-width: 1000px) {
  .grid-layout, .detail-grid { grid-template-columns: 1fr; }
}
</style>