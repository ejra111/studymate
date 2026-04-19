<script setup>
import { computed, onMounted, ref, nextTick } from 'vue'
import { loadDashboard, loadMatches, loadStudyPlan, state, getPrivateMessages, sendPrivateMessage } from '../store/appStore'

onMounted(async () => {
  await Promise.allSettled([loadDashboard(), loadMatches(), loadStudyPlan(true)])
})

const user = computed(() => state.dashboard?.user || state.user)
const stats = computed(() => state.dashboard?.stats || {
  joinedGroups: 0,
  createdGroups: 0,
  selectedCourses: 0,
  compatibilitySignal: 0
})
const recommendations = computed(() => state.dashboard?.recommendations || [])
const upcomingGroups = computed(() => state.dashboard?.upcomingGroups || [])
const studyPlan = computed(() => state.ai.studyPlan)
const topPartnerMatches = computed(() => state.matches?.partnerMatches?.slice(0, 3) || [])
const friends = computed(() => state.dashboard?.friends || [])
const isLoadingPlan = computed(() => state.loading.studyPlan)
const isLoadingDashboard = computed(() => state.loading.dashboard)

// Private Chat State
const selectedFriend = ref(null)
const privateChatMessages = ref([])
const privateChatDraft = ref('')
const isChatLoading = ref(false)

async function openPrivateChat(friend) {
  selectedFriend.value = friend
  isChatLoading.value = true
  privateChatMessages.value = []
  
  try {
    const msgs = await getPrivateMessages(friend.id)
    privateChatMessages.value = msgs
    await nextTick()
    scrollToBottom()
  } catch (err) {
    console.error('Failed to load private messages:', err)
  } finally {
    isChatLoading.value = false
  }
}

async function handleSendPrivate() {
  if (!selectedFriend.value || !privateChatDraft.value.trim()) return
  
  try {
    const msg = await sendPrivateMessage(selectedFriend.value.id, privateChatDraft.value)
    if (msg) {
      privateChatMessages.value.push(msg)
      privateChatDraft.value = ''
      await nextTick()
      scrollToBottom()
    }
  } catch (err) {
    console.error('Failed to send private message:', err)
  }
}

function scrollToBottom() {
  const box = document.querySelector('.private-chat-box')
  if (box) box.scrollTop = box.scrollHeight
}

function closeChat() {
  selectedFriend.value = null
}
</script>

<template>
  <section class="dashboard-shell">
    <header class="dashboard-header">
      <div>
        <p class="eyebrow-text">DASHBOARD PERSONAL</p>
        <h1 class="greeting">Halo, {{ user?.name || 'Mahasiswa' }}</h1>
        <p class="subtitle-text">
          Kelola ritme belajar, lihat grup aktif, dan pantau rekomendasi terbaru.
        </p>
      </div>
      <div class="mode-badge">
        <span>Mode aktif <strong>Mahasiswa</strong></span>
      </div>
    </header>

    <div class="stat-grid">
      <article class="stat-card glass-card">
        <span class="stat-label">Grup yang diikuti</span>
        <p class="stat-desc">Jumlah grup yang sudah kamu ikuti.</p>
        <strong class="stat-val-visible">{{ stats.joinedGroups || 0 }}</strong>
      </article>
      <article class="stat-card glass-card">
        <span class="stat-label">Grup yang dibuat</span>
        <p class="stat-desc">Berguna untuk monitoring kontribusi.</p>
        <strong class="stat-val-visible">{{ stats.createdGroups || 0 }}</strong>
      </article>
      <article class="stat-card glass-card">
        <span class="stat-label">Mata kuliah aktif</span>
        <p class="stat-desc">Dasar utama matchmaking.</p>
        <strong class="stat-val-visible">{{ stats.selectedCourses || 0 }}</strong>
      </article>
      <article class="stat-card glass-card">
        <span class="stat-label">Sinyal kecocokan</span>
        <p class="stat-desc">Skor partner terbaik saat ini.</p>
        <strong class="stat-val-visible">{{ stats.compatibilitySignal || 0 }}%</strong>
      </article>
    </div>

    <div class="main-grid">
      <article class="ai-planner-card glass-card" v-if="isLoadingPlan">
        <div class="card-header-with-badge">
          <div>
            <h3>AI Study Planner</h3>
            <p class="card-subtitle">Memuat rencana belajar...</p>
          </div>
          <div class="ai-badge loading-badge">Loading</div>
        </div>
        <div class="planner-skeleton">
          <div class="skeleton-line"></div>
          <div class="skeleton-line short"></div>
          <div class="skeleton-line"></div>
        </div>
      </article>

      <article class="ai-planner-card glass-card" v-else-if="studyPlan">
        <div class="card-header-with-badge">
          <div>
            <h3>AI Study Planner</h3>
            <p class="card-subtitle">{{ studyPlan.headline }}</p>
          </div>
          <div class="ai-badge">AI Active</div>
        </div>

        <p class="planner-summary">{{ studyPlan.summary }}</p>

        <div class="sessions-list" v-if="studyPlan.sessions?.length">
          <div v-for="session in studyPlan.sessions.slice(0, 3)" :key="session.courseId" class="session-card">
            <div class="session-main">
              <strong>{{ session.courseCode }} · {{ session.courseName }}</strong>
              <p>{{ session.focus }}</p>
            </div>
            <div class="session-meta">
              <span class="slot-tag">{{ session.slot }}</span>
              <span class="duration-tag">{{ session.durationMinutes }}m</span>
            </div>
          </div>
        </div>

        <div class="tips-box" v-if="studyPlan.tips?.length">
          <p class="tips-label">💡 AI Tips:</p>
          <ul>
            <li v-for="tip in studyPlan.tips.slice(0, 2)" :key="tip">{{ tip }}</li>
          </ul>
        </div>
      </article>

      <article class="ai-planner-card glass-card" v-else>
        <div class="card-header-with-badge">
          <div>
            <h3>AI Study Planner</h3>
            <p class="card-subtitle">Belum ada rencana belajar</p>
          </div>
          <div class="ai-badge" style="opacity: 0.5">Offline</div>
        </div>
        <p class="planner-summary">Lengkapi profil dan tambahkan mata kuliah aktif untuk mengaktifkan AI Planner.</p>
      </article>

      <article class="feature-card glass-card">
        <h3>Daftar Teman</h3>
        <p class="card-subtitle">Teman belajar yang sudah terhubung</p>
        
        <div v-if="friends.length" class="friend-list">
          <div v-for="friend in friends" :key="friend.id" class="friend-item">
            <div class="friend-avatar" :style="{ background: friend.avatarColor || '#6366f1' }">
              {{ friend.name?.charAt(0) }}
            </div>
            <div class="friend-info">
              <strong>{{ friend.name }}</strong>
              <span>{{ friend.program }}</span>
            </div>
            <button class="chat-btn-small" @click="openPrivateChat(friend)">Chat</button>
          </div>
        </div>
        <p v-else class="empty-text">Belum ada teman. Cari di Smart Match!</p>
      </article>

      <article class="feature-card glass-card">
        <h3>Grup terdekat</h3>
        <p class="card-subtitle">Sesi belajar yang akan kamu hadiri</p>

        <div v-if="upcomingGroups.length" class="list-content">
          <div v-for="group in upcomingGroups" :key="group.id" class="list-item">
            <strong>{{ group.title }}</strong>
            <span class="time-tag">{{ group.schedule }}</span>
          </div>
        </div>
        <p v-else class="empty-text">Belum ada grup terjadwal.</p>
      </article>

      <article class="activity-section glass-card">
        <div class="section-header">
          <h3>Aktivitas terbaru</h3>
          <p class="card-subtitle">Jejak aktivitas grup dan platform</p>
        </div>
        
        <div class="activity-list" v-if="state.dashboard?.recentActivities?.length">
          <div v-for="activity in state.dashboard.recentActivities.slice(0, 5)" :key="activity.id" class="activity-item">
            <div class="activity-dot"></div>
            <div class="activity-info">
              <strong>{{ activity.message }}</strong>
              <p>{{ activity.created_at || 'Invalid Date' }}</p>
            </div>
          </div>
        </div>
        <p v-else class="empty-text">Belum ada aktivitas terbaru.</p>
      </article>
    </div>

    <!-- Private Chat Modal/Panel -->
    <div v-if="selectedFriend" class="private-chat-overlay" @click="closeChat">
      <div class="private-chat-window glass-card" @click.stop>
        <header class="chat-header">
          <div class="friend-info">
            <div class="friend-avatar-modal" :style="{ background: selectedFriend.avatarColor || '#6366f1' }">
              {{ selectedFriend.name?.charAt(0) }}
            </div>
            <div>
              <h3>{{ selectedFriend.name }}</h3>
              <p>{{ selectedFriend.program }}</p>
            </div>
          </div>
          <button class="close-chat" @click="closeChat">×</button>
        </header>

        <div class="private-chat-box">
          <div v-if="isChatLoading" class="chat-loading">
            <div class="spinner-small"></div>
            <p>Memuat pesan...</p>
          </div>
          <div v-else-if="privateChatMessages.length === 0" class="chat-empty">
            <p>Belum ada pesan. Sapa {{ selectedFriend.name }}!</p>
          </div>
          <div
            v-for="msg in privateChatMessages"
            :key="msg.id"
            class="msg-item"
            :class="{ 'own-msg': msg.sender_id === state.user.id }"
          >
            <div class="msg-bubble">
              <p>{{ msg.message }}</p>
              <span class="msg-time">{{ new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
            </div>
          </div>
        </div>

        <footer class="chat-footer">
          <input
            v-model="privateChatDraft"
            placeholder="Ketik pesan..."
            @keyup.enter="handleSendPrivate"
            class="chat-input-field"
          />
          <button class="send-private-btn" @click="handleSendPrivate" :disabled="!privateChatDraft.trim()">
            Kirim
          </button>
        </footer>
      </div>
    </div>
  </section>
</template>

<style scoped>
.dashboard-shell { display: grid; gap: 32px; }

.dashboard-header { display: flex; justify-content: space-between; align-items: flex-start; }
.eyebrow-text { font-size: 11px; font-weight: 600; color: #64748b; letter-spacing: 0.1em; margin-bottom: 8px; }
.greeting { font-size: 42px; font-weight: 800; margin-bottom: 12px; }
.subtitle-text { color: #94a3b8; font-size: 16px; }

.mode-badge { background: rgba(255, 255, 255, 0.05); padding: 8px 16px; border-radius: 99px; border: 1px solid rgba(255, 255, 255, 0.1); font-size: 13px; }
.mode-badge strong { color: white; margin-left: 4px; }

.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.stat-card { padding: 24px; display: flex; flex-direction: column; gap: 4px; }
.stat-label { font-size: 12px; font-weight: 500; color: #94a3b8; }
.stat-desc { font-size: 13px; color: #64748b; margin-bottom: 8px; }
.stat-val-visible { font-size: 28px; font-weight: 800; color: white; margin-top: auto; }

.main-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.ai-planner-card { grid-column: span 2; padding: 24px; border: 1px solid rgba(99, 102, 241, 0.2); }
.card-header-with-badge { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.ai-badge { padding: 4px 10px; border-radius: 8px; background: rgba(99, 102, 241, 0.2); color: #818cf8; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid rgba(99, 102, 241, 0.3); }
.planner-summary { font-size: 14px; color: #94a3b8; margin-bottom: 20px; }

.sessions-list { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
.session-card { padding: 16px; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--line); border-radius: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; }
.session-main strong { font-size: 14px; color: white; display: block; margin-bottom: 4px; }
.session-main p { font-size: 12px; color: #64748b; line-height: 1.4; }
.session-meta { display: flex; gap: 8px; }
.slot-tag { font-size: 10px; font-weight: 700; color: #818cf8; }
.duration-tag { font-size: 10px; font-weight: 700; color: #64748b; }

.tips-box { padding: 16px; background: rgba(99, 102, 241, 0.05); border-radius: 12px; border: 1px dotted rgba(99, 102, 241, 0.2); }
.tips-label { font-size: 13px; font-weight: 700; color: #818cf8; margin-bottom: 8px; }
.tips-box ul { margin: 0; padding-left: 20px; }
.tips-box li { font-size: 13px; color: #94a3b8; margin-bottom: 4px; }

.feature-card { padding: 24px; }
.feature-card h3 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.card-subtitle { font-size: 13px; color: #64748b; margin-bottom: 20px; }

.activity-section { grid-column: span 2; padding: 24px; }
.section-header { margin-bottom: 24px; }

.list-content { display: grid; gap: 12px; }
.list-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; background: rgba(255, 255, 255, 0.02); border-radius: 12px; }

.friend-list { display: grid; gap: 12px; }
.friend-item { display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255, 255, 255, 0.02); border-radius: 12px; }
.friend-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: white; }
.friend-info { flex: 1; display: flex; flex-direction: column; }
.friend-info strong { font-size: 14px; color: white; }
.friend-info span { font-size: 11px; color: #64748b; }
.chat-btn-small { padding: 6px 12px; border-radius: 8px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); color: #818cf8; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.chat-btn-small:hover { background: var(--primary); color: white; border-color: var(--primary); }

/* Private Chat UI */
.private-chat-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.private-chat-window { width: 100%; max-width: 450px; height: 550px; display: flex; flex-direction: column; overflow: hidden; background: #0f172a; }
.chat-header { padding: 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; }
.chat-header .friend-info { display: flex; gap: 12px; align-items: center; }
.chat-header .friend-avatar-modal { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; color: white; }
.chat-header h3 { margin: 0; font-size: 16px; color: white; }
.chat-header p { margin: 0; font-size: 12px; color: #64748b; }
.close-chat { background: none; border: none; color: #94a3b8; font-size: 24px; cursor: pointer; padding: 0 8px; }

.private-chat-box { flex: 1; padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; background: rgba(0, 0, 0, 0.2); }
.chat-loading, .chat-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; font-size: 14px; gap: 12px; }
.msg-item { display: flex; flex-direction: column; max-width: 80%; }
.msg-bubble { padding: 10px 14px; border-radius: 14px; background: rgba(255, 255, 255, 0.05); }
.msg-bubble p { margin: 0; font-size: 14px; line-height: 1.5; color: #cbd5e1; }
.msg-time { display: block; font-size: 10px; color: #64748b; margin-top: 4px; text-align: right; }
.own-msg { align-self: flex-end; }
.own-msg .msg-bubble { background: #312e81; border-bottom-right-radius: 4px; }
.own-msg .msg-time { color: #818cf8; }

.chat-footer { padding: 16px; border-top: 1px solid var(--line); display: flex; gap: 10px; background: rgba(15, 23, 42, 0.4); }
.chat-input-field { flex: 1; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); color: white; padding: 10px 16px; border-radius: 12px; outline: none; }
.send-private-btn { background: var(--primary); color: white; border: none; padding: 0 16px; border-radius: 12px; font-weight: 700; cursor: pointer; }
.send-private-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.spinner-small { width: 24px; height: 24px; border: 3px solid rgba(99, 102, 241, 0.1); border-top-color: #6366f1; border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.score-tag, .time-tag { font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 8px; background: rgba(99, 102, 241, 0.1); color: #818cf8; }

.activity-list { display: grid; gap: 20px; }
.activity-item { display: flex; gap: 16px; align-items: flex-start; }
.activity-dot { width: 10px; height: 10px; border-radius: 50%; background: #6366f1; margin-top: 6px; box-shadow: 0 0 10px rgba(99, 102, 241, 0.5); }
.activity-info strong { display: block; font-size: 14px; margin-bottom: 4px; }
.activity-info p { font-size: 12px; color: #64748b; }

.empty-text { color: #475569; font-size: 14px; }

.planner-skeleton { display: grid; gap: 12px; padding: 12px 0; }
.skeleton-line { height: 14px; background: rgba(255, 255, 255, 0.05); border-radius: 8px; animation: pulse 1.5s ease-in-out infinite; }
.skeleton-line.short { width: 60%; }
@keyframes pulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.8; } }
.loading-badge { animation: pulse 1.5s ease-in-out infinite; }

@media (max-width: 1100px) {
  .stat-grid { grid-template-columns: repeat(2, 1fr); }
  .main-grid { grid-template-columns: 1fr; }
  .activity-section { grid-column: span 1; }
  .ai-planner-card { grid-column: span 1; }
}
</style>
