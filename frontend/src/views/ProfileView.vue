<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import SectionCard from '../components/SectionCard.vue'
import { getScheduleHistory, loadUser, pushToast, state, updateProfile, uploadAvatarFile } from '../store/appStore'

const form = reactive({
  name: '',
  email: '',
  university: '',
  programName: '',
  semester: '',
  bio: '',
  interestsText: '',
  courseCodesText: '',
  availability: [],
  avatarColor: '#4f46e5',
})

const scheduleInput = ref('')
const scheduleInputError = ref('')
const uploading = ref(false)
const avatarLocalPreview = ref(null)
const boot = computed(() => state.boot)
const scheduleHistory = ref([])

// === Schedule Constants ===
const VALID_DAYS = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU']

const DAY_ALIASES = {
  'SEN': 'SENIN', 'SEL': 'SELASA', 'RAB': 'RABU', 'KAM': 'KAMIS',
  'JUM': 'JUMAT', "JUM'AT": 'JUMAT', 'SAB': 'SABTU', 'MIN': 'MINGGU', 'MING': 'MINGGU',
  'MONDAY': 'SENIN', 'TUESDAY': 'SELASA', 'WEDNESDAY': 'RABU',
  'THURSDAY': 'KAMIS', 'FRIDAY': 'JUMAT', 'SATURDAY': 'SABTU', 'SUNDAY': 'MINGGU',
}

function normalizeScheduleSlot(raw) {
  let text = String(raw || '').trim().toUpperCase()
  text = text.replace(/\bJAM\b/g, '').replace(/\bPUKUL\b/g, '').trim()

  // Handle time words
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

  // Normalize bare number
  text = text.replace(/\b(\d{1,2})\b(?!:|\d)/g, (_, h) => {
    return String(parseInt(h)).padStart(2, '0') + ':00'
  })

  text = text.replace(/\s+/g, ' ').trim()

  // Extract day and time
  const parts = text.split(/\s+/)
  let day = null
  let time = null

  for (const part of parts) {
    if (VALID_DAYS.includes(part)) {
      day = part
    } else if (DAY_ALIASES[part]) {
      day = DAY_ALIASES[part]
    } else if (/^\d{1,2}:\d{2}$/.test(part)) {
      time = part
    }
  }

  if (day && time) {
    const [hh, mm] = time.split(':').map(Number)
    if (hh >= 0 && hh <= 23 && mm >= 0 && mm <= 59) {
      return `${day} ${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`
    }
  }

  return null
}

function addScheduleSlot() {
  const raw = scheduleInput.value.trim()
  if (!raw) return

  const normalized = normalizeScheduleSlot(raw)
  if (!normalized) {
    scheduleInputError.value = 'Format tidak valid. Contoh: SENIN 19:00 atau "senin jam 7 malam"'
    return
  }

  if (form.availability.includes(normalized)) {
    scheduleInputError.value = 'Slot ini sudah ditambahkan.'
    return
  }

  form.availability = [...form.availability, normalized]
  scheduleInput.value = ''
  scheduleInputError.value = ''
}

function removeSlot(slot) {
  form.availability = form.availability.filter(s => s !== slot)
}

function loadScheduleHistoryData() {
  scheduleHistory.value = getScheduleHistory()
}

const avatarPreviewSrc = computed(() => {
  if (avatarLocalPreview.value) return avatarLocalPreview.value
  return state.user?.avatarUrl || state.user?.avatar_url || null
})

function hydrateForm() {
  if (!state.user) return

  form.name = state.user.name || ''
  form.email = state.user.email || ''
  form.university = state.user.university || ''
  form.programName = state.user.programName || state.user.program_name || ''
  form.semester = state.user.semester || ''
  form.bio = state.user.bio || ''
  form.interestsText = (state.user.interests || []).join(', ').toUpperCase()
  form.courseCodesText = (state.user.courses || []).map((course) => course.code || course.name).join(', ').toUpperCase()
  form.availability = [...(state.user.availability || [])]
  form.avatarColor = state.user.avatarColor || state.user.avatar_color || '#4f46e5'
}

watch(
  () => state.user,
  () => hydrateForm(),
  { deep: true }
)

onMounted(async () => {
  try {
    await loadUser()
    hydrateForm()
    loadScheduleHistoryData()
  } catch (error) {
    pushToast(error.message, 'error')
  }
})

function upperField(field) {
  form[field] = String(form[field] || '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ')
}

function normalizeInterests() {
  form.interestsText = String(form.interestsText || '')
    .split(',')
    .map((item) => item.trim().toUpperCase())
    .filter(Boolean)
    .join(', ')
}

function normalizeCourseCodes() {
  form.courseCodesText = String(form.courseCodesText || '')
    .toUpperCase()
    .replace(/[^A-Z0-9, ]/g, '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
    .join(', ')
}

function applyHistoryEntry(entry) {
  form.availability = [...entry.slots]
  pushToast('Jadwal dari riwayat berhasil dimuat.', 'info')
}

async function onAvatarSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return

  const localUrl = URL.createObjectURL(file)

  try {
    uploading.value = true
    avatarLocalPreview.value = localUrl
    await uploadAvatarFile(file)
    hydrateForm()
  } catch (error) {
    pushToast(error.message, 'error')
  } finally {
    uploading.value = false
    avatarLocalPreview.value = null
    event.target.value = ''
    URL.revokeObjectURL(localUrl)
  }
}

async function submitProfile() {
  try {
    const courseTokens = String(form.courseCodesText || '')
      .split(',')
      .map((item) => item.trim().toUpperCase())
      .filter(Boolean)

    const courseIds = Array.from(
      new Set(
        courseTokens
          .map((token) => {
            const courseList = boot.value?.courses || []
            const found = courseList.find(
              (course) =>
                String(course.code || '').toUpperCase() === token ||
                String(course.name || '').toUpperCase() === token
            )

            return found?.id || null
          })
          .filter(Boolean)
      )
    )

    await updateProfile({
      name: form.name,
      email: form.email,
      university: form.university.toUpperCase(),
      programName: form.programName.toUpperCase(),
      semester: Number(form.semester),
      bio: form.bio,
      interests: String(form.interestsText || '')
        .split(',')
        .map((item) => item.trim().toUpperCase())
        .filter(Boolean),
      courseIds,
      availability: [...form.availability],
      avatarColor: form.avatarColor,
    })

    await loadUser()
    hydrateForm()
    loadScheduleHistoryData()
  } catch (error) {
    pushToast(error.message, 'error')
  }
}
</script>

<template>
  <section class="profile-shell">
    <header class="profile-header">
      <div>
        <p class="eyebrow-text">PROFIL AKADEMIK</p>
        <h1 class="greeting">Kelola Profil</h1>
        <p class="subtitle-text">
          Lengkapi data dirimu untuk mendapatkan rekomendasi partner belajar yang lebih akurat.
        </p>
      </div>
    </header>

    <div class="profile-grid">
      <article class="panel glass-card main-info">
        <div class="section-head">
          <h3>Data Utama</h3>
          <p>Informasi dasar identitas mahasiswa Anda.</p>
        </div>

        <form class="profile-form" @submit.prevent="submitProfile">
          <div class="avatar-section">
            <div class="avatar-wrapper">
              <img v-if="avatarPreviewSrc" :src="avatarPreviewSrc" alt="Avatar" class="profile-avatar-img" />
              <div v-else class="avatar-placeholder" :style="{ backgroundColor: form.avatarColor }">
                {{ form.name?.slice(0, 1) || '?' }}
              </div>
              <label class="avatar-upload-btn">
                <input type="file" accept="image/*" @change="onAvatarSelected" hidden />
                <span class="upload-icon">📷</span>
              </label>
            </div>
            <div class="avatar-info">
              <strong>{{ form.name || 'Nama Belum Diisi' }}</strong>
              <p>{{ uploading ? 'Sedang mengunggah...' : 'Klik ikon kamera untuk ganti foto' }}</p>
            </div>
          </div>

          <div class="form-grid-inner">
            <div class="field-group">
              <label>Nama Lengkap</label>
              <input v-model="form.name" type="text" placeholder="Masukkan nama" />
            </div>
            <div class="field-group">
              <label>Email</label>
              <input v-model="form.email" type="email" placeholder="nama@kampus.ac.id" />
            </div>
            <div class="field-group">
              <label>Universitas</label>
              <input v-model="form.university" type="text" placeholder="Contoh: UNIVERSITAS INDONESIA" @input="upperField('university')" />
            </div>
            <div class="field-group">
              <label>Program Studi</label>
              <input v-model="form.programName" type="text" placeholder="Contoh: INFORMATIKA" @input="upperField('programName')" />
            </div>
            <div class="field-group">
              <label>Semester</label>
              <input v-model.number="form.semester" type="number" min="1" max="14" placeholder="1-14" />
            </div>
            <div class="field-group">
              <label>Warna Tema Avatar</label>
              <input v-model="form.avatarColor" type="color" class="color-picker" />
            </div>
            <div class="field-group full-span">
              <label>Bio Singkat</label>
              <textarea v-model="form.bio" rows="3" placeholder="Ceritakan sedikit tentang dirimu..."></textarea>
            </div>
          </div>

          <button class="primary-btn save-btn" :disabled="uploading">Simpan Perubahan</button>
        </form>
      </article>

      <div class="side-sections">
        <article class="panel glass-card academic-section">
          <div class="section-head">
            <h3>Akademik & Minat</h3>
            <p>Membantu algoritma Smart Match.</p>
          </div>
          
          <form @submit.prevent="submitProfile">
            <div class="form-grid-inner">
              <div class="field-group full-span">
                <label>Minat (Pisahkan dengan koma)</label>
                <input v-model="form.interestsText" placeholder="WEB DEV, AI, UI/UX" @blur="normalizeInterests" />
              </div>
              <div class="field-group full-span">
                <label>Mata Kuliah Aktif (Kode/Nama)</label>
                <textarea v-model="form.courseCodesText" rows="2" placeholder="IF101, IF102" @blur="normalizeCourseCodes"></textarea>
                <small class="hint">Sistem akan mencocokkan dengan data kurikulum yang tersedia.</small>
              </div>
            </div>
            <button class="primary-btn save-btn">Simpan Akademik & Minat</button>
          </form>
        </article>

        <article class="panel glass-card availability-section">
          <div class="section-head">
            <h3>Ketersediaan Waktu</h3>
            <p>Ketik jadwal belajar favoritmu.</p>
          </div>

          <form @submit.prevent="submitProfile">
            <div v-if="scheduleHistory.length" class="history-section">
              <p class="history-label">📌 Riwayat jadwal sebelumnya:</p>
              <div class="history-chips">
                <button
                  v-for="(entry, idx) in scheduleHistory.slice(0, 5)"
                  :key="idx"
                  type="button"
                  class="history-chip"
                  @click="applyHistoryEntry(entry)"
                >
                  {{ entry.slots.slice(0, 3).join(', ') }}{{ entry.slots.length > 3 ? ` +${entry.slots.length - 3}` : '' }}
                </button>
              </div>
            </div>

            <!-- Manual Schedule Input -->
            <div class="schedule-input-area">
              <label>Tambah Slot Waktu</label>
              <div class="schedule-input-row">
                <input
                  v-model="scheduleInput"
                  placeholder='Ketik: "SENIN 19:00" atau "selasa jam 8 pagi"'
                  @keyup.enter="addScheduleSlot"
                  class="schedule-text-input"
                />
                <button type="button" class="add-slot-btn" @click="addScheduleSlot">+ Tambah</button>
              </div>
              <small v-if="scheduleInputError" class="schedule-error">{{ scheduleInputError }}</small>
              <small v-else class="hint">Format: HARI HH:mm · Contoh: SENIN 19:00, RABU 08:30</small>
            </div>

            <!-- Active Slots as Chips -->
            <div class="active-slots" v-if="form.availability.length">
              <p class="slots-label">Slot aktif:</p>
              <div class="slot-chips">
                <span
                  v-for="slot in form.availability"
                  :key="slot"
                  class="slot-chip"
                >
                  {{ slot }}
                  <button type="button" class="chip-remove" @click="removeSlot(slot)">×</button>
                </span>
              </div>
            </div>
            <p v-else class="no-slots-text">Belum ada slot waktu dipilih.</p>

            <button class="primary-btn save-btn">Simpan Ketersediaan</button>
          </form>
        </article>
      </div>
    </div>
  </section>
</template>

<style scoped>
.profile-shell { display: grid; gap: 32px; }
.profile-header { margin-bottom: 8px; }
.eyebrow-text { font-size: 11px; font-weight: 600; color: #64748b; letter-spacing: 0.1em; margin-bottom: 8px; }
.greeting { font-size: 42px; font-weight: 800; margin-bottom: 12px; color: white; }
.subtitle-text { color: #94a3b8; font-size: 16px; }

.profile-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 24px; }
.panel { padding: 32px; }
.section-head { margin-bottom: 24px; }
.section-head h3 { font-size: 20px; font-weight: 700; margin-bottom: 4px; color: white; }
.section-head p { font-size: 14px; color: #64748b; }

.avatar-section { display: flex; align-items: center; gap: 20px; margin-bottom: 32px; padding: 20px; background: rgba(255, 255, 255, 0.02); border-radius: 20px; border: 1px solid var(--line); }
.avatar-wrapper { position: relative; width: 80px; height: 80px; }
.profile-avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 2px solid var(--line); display: grid; place-items: center; font-size: 32px; font-weight: 800; color: white; }
.avatar-upload-btn { position: absolute; bottom: 0; right: 0; background: var(--primary); width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; cursor: pointer; border: 2px solid var(--bg); transition: transform 0.2s; }
.avatar-upload-btn:hover { transform: scale(1.1); }
.avatar-info strong { display: block; font-size: 18px; color: white; margin-bottom: 4px; }
.avatar-info p { font-size: 13px; color: #64748b; }

.form-grid-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.full-span { grid-column: span 2; }
.field-group { display: flex; flex-direction: column; gap: 8px; }
.field-group label { font-size: 13px; font-weight: 600; color: #94a3b8; }
.hint { font-size: 11px; color: #475569; margin-top: 4px; }

.color-picker { height: 46px; padding: 4px; cursor: pointer; }
.save-btn { margin-top: 32px; width: 100%; padding: 14px; font-size: 16px; }

.side-sections { display: grid; gap: 24px; }

/* Schedule Input Styles */
.schedule-input-area { margin-bottom: 20px; }
.schedule-input-area label { display: block; font-size: 13px; font-weight: 600; color: #94a3b8; margin-bottom: 8px; }
.schedule-input-row { display: flex; gap: 8px; }
.schedule-text-input {
  flex: 1;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid var(--line);
  color: white;
  padding: 10px 14px;
  border-radius: 10px;
  outline: none;
  font-size: 13px;
  transition: border-color 0.2s;
}
.schedule-text-input:focus { border-color: #6366f1; }
.schedule-text-input::placeholder { color: #475569; }
.add-slot-btn {
  padding: 10px 16px;
  background: rgba(99, 102, 241, 0.15);
  border: 1px solid rgba(99, 102, 241, 0.3);
  color: #818cf8;
  border-radius: 10px;
  font-weight: 600;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}
.add-slot-btn:hover { background: rgba(99, 102, 241, 0.25); }
.schedule-error { font-size: 11px; color: #f87171; font-weight: 600; display: block; margin-top: 6px; }

.active-slots { margin-top: 16px; }
.slots-label { font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 10px; }
.slot-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.slot-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: rgba(99, 102, 241, 0.15);
  border: 1px solid rgba(99, 102, 241, 0.3);
  border-radius: 10px;
  color: #a5b4fc;
  font-size: 12px;
  font-weight: 700;
  transition: all 0.2s;
}
.chip-remove {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 16px;
  cursor: pointer;
  padding: 0;
  line-height: 1;
  transition: color 0.2s;
}
.chip-remove:hover { color: #f87171; }
.no-slots-text { font-size: 13px; color: #475569; margin-top: 12px; }

@media (max-width: 1200px) {
  .profile-grid { grid-template-columns: 1fr; }
}

.history-section { margin-bottom: 16px; }
.history-label { font-size: 13px; font-weight: 600; color: #818cf8; margin-bottom: 10px; }
.history-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.history-chip {
  padding: 8px 14px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
  background: rgba(99, 102, 241, 0.1);
  border: 1px solid rgba(99, 102, 241, 0.25);
  color: #a5b4fc;
  cursor: pointer;
  transition: all 0.2s;
}
.history-chip:hover {
  background: rgba(99, 102, 241, 0.25);
  color: white;
  transform: translateY(-1px);
}
</style>
