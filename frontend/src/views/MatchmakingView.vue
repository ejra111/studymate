<script setup>
import { computed, onMounted, ref, watch, nextTick } from 'vue'
import { joinGroup, loadMatches, pushToast, sendStudyInvite, state, getPrivateMessages, sendPrivateMessage, loadDashboard } from '../store/appStore'

const searchQuery = ref('')
let debounceTimer = null

// Private Chat State
const selectedFriend = ref(null)
const privateChatMessages = ref([])
const privateChatDraft = ref('')
const isChatLoading = ref(false)

onMounted(async () => {
  await Promise.all([loadMatches(), loadDashboard()])
})

watch(searchQuery, (val) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(async () => {
    await loadMatches({ search: val })
  }, 300)
})

const isLoading = computed(() => state.loading.matches)
const partnerMatches = computed(() => (state.matches?.partnerMatches || []).filter(m => m.score >= 10))
const groupMatches = computed(() => (state.matches?.groupMatches || []).filter(m => m.score >= 10))
const smartMeta = computed(() => state.matches?.smartMatchMeta)
const friends = computed(() => state.dashboard?.friends || [])

async function handleJoin(groupId) {
  try {
    await joinGroup(groupId)
  } catch (error) {
    console.error(error)
  }
}

async function handlePartnerMatch(user) {
  try {
    await sendStudyInvite(user)
  } catch (err) {
    console.error('Failed to send study invite:', err)
  }
}

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
  <section class="match-shell">
    <header class="match-hero glass-card">
      <div class="hero-left">
        <p class="eyebrow">Smart Match 2.0</p>
        <h1>Rekomendasi teman dan grup belajar</h1>
        <p class="subtitle">
          Mesin rekomendasi memadukan mata kuliah, availability, minat, semester, grafik sosial, dan konteks bio.
        </p>

        <div class="search-box">
          <input 
            v-model="searchQuery" 
            type="text" 
            placeholder="Cari partner atau grup..." 
            class="search-input"
          />
        </div>
      </div>
      <div class="meta-card" v-if="smartMeta">
        <strong>{{ smartMeta.aiMode }}</strong>
        <small>{{ smartMeta.strategy }}</small>
      </div>
    </header>

    <div v-if="isLoading" class="loading-bar">
      <div class="loading-bar-inner"></div>
    </div>

    <div class="section-grid single-panel">
      <!-- Friend List Section -->
      <article class="panel glass-card friend-list-panel" v-if="friends.length">
        <div class="panel-head">
          <div>
            <p class="eyebrow">Daftar Teman</p>
            <h2>Partner Belajar Kamu</h2>
          </div>
        </div>
        <div class="friend-grid-mini">
          <div v-for="friend in friends" :key="friend.id" class="friend-card-mini" @click="openPrivateChat(friend)">
            <div class="friend-avatar-mini" :style="{ background: friend.avatarColor || '#6366f1' }">
              {{ friend.name?.charAt(0) }}
            </div>
            <div class="friend-info-mini">
              <strong>{{ friend.name }}</strong>
              <span>{{ friend.program }}</span>
            </div>
            <div class="chat-status-icon">💬</div>
          </div>
        </div>
      </article>

      <article class="panel glass-card">
        <div class="panel-head">
          <div>
            <p class="eyebrow">Partner Match</p>
            <h2>Top candidate</h2>
            <p class="muted">Ajak partner belajar yang cocok untuk berteman dan chat bersama.</p>
          </div>
        </div>
        <div v-if="partnerMatches.length" class="card-list">
          <div v-for="item in partnerMatches" :key="item.user.id" class="match-card">
            <div class="match-head">
              <div>
                <strong>{{ item.user.name }}</strong>
                <p class="muted">{{ item.user.program?.name || item.user.program_name || 'Program belum diisi' }}</p>
              </div>
              <div class="score-wrap">
                <span class="score">{{ item.score }}</span>
                <small>{{ item.confidence }}</small>
              </div>
            </div>

            <p class="narrative">{{ item.matchNarrative }}</p>

            <ul class="reason-list">
              <li v-for="reason in item.reasons" :key="reason">{{ reason }}</li>
            </ul>

            <div class="breakdown-grid" v-if="item.breakdown">
              <div v-for="br in item.breakdown" :key="br.label" class="br-item">
                <span class="br-label">{{ br.label }}</span>
                <span class="br-val">+{{ br.score }}</span>
              </div>
            </div>

            <div class="chip-row">
              <span v-for="course in item.sharedCourses" :key="course.id" class="chip">{{ course.code }}</span>
              <span v-for="interest in item.sharedInterests" :key="interest" class="chip alt">{{ interest }}</span>
            </div>

            <button class="match-btn primary-btn" @click="handlePartnerMatch(item.user)">
              Ajak Belajar
            </button>
          </div>
        </div>
        <p v-else class="muted-text">Belum ada partner match yang cukup kuat.</p>
      </article>
    </div>

    <!-- Private Chat Modal/Panel -->
    <div v-if="selectedFriend" class="private-chat-overlay" @click="closeChat">
      <div class="private-chat-window glass-card" @click.stop>
        <header class="chat-header">
          <div class="friend-info">
            <div class="friend-avatar" :style="{ background: selectedFriend.avatarColor || '#6366f1' }">
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
.hero-left { flex: 1; display: grid; gap: 12px; }
.search-box { margin-top: 10px; max-width: 400px; }
.search-input { width: 100%; padding: 12px 16px; border: 1px solid var(--line); border-radius: 12px; background: rgba(15, 23, 42, 0.6); color: white; outline: none; transition: border-color 0.2s; }
.search-input:focus { border-color: var(--primary); }
.match-shell { display: grid; gap: 20px; }
.match-hero, .panel { padding: 24px; }
.match-hero { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; }
.eyebrow { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: #64748b; font-weight: 600; }
h1, h2 { margin: 0; color: white; }
.subtitle { color: #94a3b8; line-height: 1.6; }
.meta-card { max-width: 280px; padding: 16px; border-radius: 16px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); display: grid; gap: 6px; }
.meta-card strong { color: #818cf8; }
.meta-card small { color: #94a3b8; }
.section-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.single-panel { grid-template-columns: 1fr; max-width: 800px; margin: 0 auto; }
.card-list { display: grid; gap: 14px; }
.match-card { border: 1px solid var(--line); border-radius: 16px; padding: 16px; display: grid; gap: 12px; background: rgba(255, 255, 255, 0.02); }
.match-head { display: flex; justify-content: space-between; gap: 12px; }
.score-wrap { min-width: 70px; text-align: center; padding: 10px 12px; border-radius: 14px; background: rgba(99, 102, 241, 0.1); color: #818cf8; display: grid; }
.score { font-size: 24px; font-weight: 800; }
.breakdown-grid { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px; background: rgba(255, 255, 255, 0.03); border-radius: 12px; margin: 4px 0; }
.br-item { font-size: 11px; display: flex; gap: 4px; color: #64748b; font-weight: 600; }
.br-val { color: #818cf8; }
.narrative { margin: 0; color: #cbd5e1; }
.reason-list { margin: 0; padding-left: 18px; color: #94a3b8; font-size: 14px; }
.chip-row { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { display: inline-flex; align-items: center; border-radius: 999px; padding: 6px 12px; background: rgba(99, 102, 241, 0.15); color: #818cf8; font-size: 12px; font-weight: 700; }
.chip.alt { background: rgba(255, 255, 255, 0.05); color: #cbd5e1; }
.muted { color: #64748b; font-size: 14px; }
.muted-text { color: #475569; font-size: 14px; padding: 20px; text-align: center; }
.match-btn { width: 100%; margin-top: 10px; padding: 10px; font-size: 13px; }
.primary-btn { border: 0; border-radius: 12px; padding: 12px 14px; background: var(--primary); color: #fff; font-weight: 700; cursor: pointer; transition: opacity 0.2s; }
.primary-btn:hover { opacity: 0.9; }

.loading-bar { width: 100%; height: 3px; background: rgba(99, 102, 241, 0.1); border-radius: 4px; overflow: hidden; margin-bottom: 8px; }
.loading-bar-inner { width: 40%; height: 100%; background: linear-gradient(90deg, #6366f1, #8b5cf6); border-radius: 4px; animation: loading-slide 1.2s ease-in-out infinite; }
@keyframes loading-slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(350%); } }

/* Friend Grid Mini */
.friend-grid-mini { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-top: 16px; }
.friend-card-mini { background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); padding: 12px; border-radius: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer; transition: all 0.2s; }
.friend-card-mini:hover { background: rgba(99, 102, 241, 0.1); border-color: rgba(99, 102, 241, 0.3); transform: translateY(-2px); }
.friend-avatar-mini { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: white; }
.friend-info-mini { flex: 1; display: flex; flex-direction: column; }
.friend-info-mini strong { font-size: 13px; color: white; }
.friend-info-mini span { font-size: 10px; color: #64748b; }
.chat-status-icon { font-size: 14px; opacity: 0.6; }

/* Private Chat UI */
.private-chat-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.private-chat-window { width: 100%; max-width: 450px; height: 550px; display: flex; flex-direction: column; overflow: hidden; }
.chat-header { padding: 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; }
.chat-header .friend-info { display: flex; gap: 12px; align-items: center; }
.chat-header .friend-avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; color: white; }
.chat-header h3 { margin: 0; font-size: 16px; }
.chat-header p { margin: 0; font-size: 12px; color: #64748b; }
.close-chat { background: none; border: none; color: #94a3b8; font-size: 24px; cursor: pointer; padding: 0 8px; }

.private-chat-box { flex: 1; padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; background: rgba(0, 0, 0, 0.1); }
.chat-loading, .chat-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; font-size: 14px; gap: 12px; }
.msg-item { display: flex; flex-direction: column; max-width: 80%; }
.msg-bubble { padding: 10px 14px; border-radius: 14px; background: rgba(255, 255, 255, 0.05); }
.msg-bubble p { margin: 0; font-size: 14px; line-height: 1.5; color: #cbd5e1; }
.msg-time { display: block; font-size: 10px; color: #64748b; margin-top: 4px; text-align: right; }
.own-msg { align-self: flex-end; }
.own-msg .msg-bubble { background: #312e81; border-bottom-right-radius: 4px; }
.own-msg .msg-time { color: #818cf8; }

.chat-footer { padding: 16px; border-top: 1px solid var(--line); display: flex; gap: 10px; }
.chat-input-field { flex: 1; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); color: white; padding: 10px 16px; border-radius: 12px; outline: none; }
.send-private-btn { background: var(--primary); color: white; border: none; padding: 0 16px; border-radius: 12px; font-weight: 700; cursor: pointer; }
.send-private-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.spinner-small { width: 24px; height: 24px; border: 3px solid rgba(99, 102, 241, 0.1); border-top-color: #6366f1; border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 900px) { .section-grid { grid-template-columns: 1fr; } .match-hero { flex-direction: column; } }
</style>
