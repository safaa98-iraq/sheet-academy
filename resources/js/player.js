import { flushProgress, getProgress, saveProgress, storageScope } from './progress-store';

export const formatTime = (seconds) => `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;

function readPreference(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

function writePreference(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        document.dispatchEvent(new CustomEvent('academy:storage-unavailable'));
    }
}

function createQualityAdapter(player, onPreferenceChange) {
    const key = `academy:quality:${storageScope()}`;
    const options = ['auto', '1080', '720', '480', '360', '240'];
    let preference = player.dataset.preferredQuality?.replace(/p$/, '') || readPreference(key, 'auto');
    if (!options.includes(preference)) preference = 'auto';
    let effectiveHeight = 720;
    let hls = null;
    const button = player.querySelector('[data-quality-toggle]');
    const menu = player.querySelector('[data-quality-options]');

    const render = () => {
        player.querySelector('[data-quality-label]').textContent = preference === 'auto' ? 'تلقائي' : `${preference}p`;
        player.querySelector('[data-auto-quality]').textContent = `(${effectiveHeight}p)`;
        player.querySelectorAll('[data-quality-value]').forEach((option) => {
            const selected = option.dataset.qualityValue === preference;
            option.setAttribute('aria-pressed', String(selected));
            option.querySelector('[data-quality-check]').textContent = selected ? '✓' : '';
        });
        player.dataset.quality = preference;
    };
    const apply = () => {
        if (!hls) return;
        if (preference === 'auto') {
            hls.currentLevel = -1;
            return;
        }
        const level = hls.levels.findIndex((item) => Number(item.height) === Number(preference));
        if (level !== -1) hls.currentLevel = level;
    };
    const close = () => {
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
    };
    button.addEventListener('click', () => {
        menu.hidden = !menu.hidden;
        button.setAttribute('aria-expanded', String(!menu.hidden));
    });
    menu.querySelectorAll('[data-quality-value]').forEach((option) => option.addEventListener('click', () => {
        preference = option.dataset.qualityValue;
        writePreference(key, preference);
        apply();
        onPreferenceChange({video_quality: preference === 'auto' ? 'auto' : `${preference}p`});
        render();
        close();
        button.focus();
    }));
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.player-quality')) close();
    });
    player.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) {
            close();
            button.focus();
        }
    });
    render();

    return {
        attachHls(instance) {
            if (!instance || !Array.isArray(instance.levels)) return;
            hls = instance;
            apply();
        },
        setEffectiveQuality(height) {
            if (Number.isFinite(Number(height)) && Number(height) > 0) {
                effectiveHeight = Number(height);
                render();
            }
        },
        get preference() { return preference; },
    };
}

function initializePlayer(player) {
    if (player.dataset.initialized) return player.academyPlayer;
    player.dataset.initialized = 'true';
    const page = player.closest('[data-lesson-page]');
    const lessonId = player.dataset.lessonId;
    let duration = Math.max(1, Number(player.dataset.duration) || 750);
    const media = player.querySelector('[data-hls-video]');
    let hls = null;
    let streamRenewalTimer = null;
    let auditHeartbeatTimer = null;
    let progressHeartbeatTimer = null;
    let watermarkObserver = null;
    let watchStarted = false;
    const saved = getProgress(lessonId);
    const settings = window.ACADEMY_SETTINGS || {};
    player.querySelector('[data-player-watermark]').hidden = settings.watermark === false;
    let position = Math.min(duration, saved.updatedAt ? saved.position : Math.max(0, Number(player.dataset.initialPosition) || 0));
    if (settings.resume === false) position = 0;
    let watched = Math.min(duration, saved.updatedAt ? saved.watched : 0);
    let completed = saved.updatedAt ? saved.completed : saved.completed || player.dataset.initialCompleted === 'true';
    let playing = false;
    let playbackRate = Math.min(2, Math.max(0.5, Number(player.dataset.preferredSpeed) || 1));
    let volume = player.dataset.preferredMuted === 'true' ? 0 : 0.8;
    let previousVolume = 0.8;
    let lastTick = performance.now();
    const seek = player.querySelector('[data-seek]');
    const resume = player.querySelector('[data-player-resume]');
    const qualityAdapter = createQualityAdapter(player, persistPreference);

    function persistPreference(values) {
        if (!player.dataset.streamSessionUrl) return;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) return;
        fetch('/player-preferences', {
            method: 'PUT', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify(values),
        }).catch(() => {});
    }

    const refreshStream = async (restorePlayback = Boolean(media?.paused === false)) => {
        if (!media || !player.dataset.streamSessionUrl) return;
        const response = await fetch(player.dataset.streamSessionUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        if (!response.ok) throw new Error('stream-session-expired');
        const {manifest_url: manifestUrl} = await response.json();
        const savedPosition = media.currentTime || position;
        const {default: Hls} = await import('hls.js/light');
        if (Hls.isSupported()) {
            if (hls) hls.destroy();
            hls = new Hls({enableWorker: true, xhrSetup: (xhr) => { xhr.withCredentials = true; }});
            hls.on(Hls.Events.MANIFEST_PARSED, () => {
                qualityAdapter.attachHls(hls);
                if (savedPosition > 0) media.currentTime = savedPosition;
                if (restorePlayback) media.play().catch(() => {});
            });
            hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => qualityAdapter.setEffectiveQuality(hls.levels[data.level]?.height));
            hls.on(Hls.Events.ERROR, (_, data) => {
                if (data.fatal) announce('تعذّر تحميل الفيديو. تحقّق من اتصالك ثم أعد تحميل الدرس.');
            });
            hls.loadSource(manifestUrl);
            hls.attachMedia(media);
            return;
        }
        if (media.canPlayType('application/vnd.apple.mpegurl')) {
            media.src = manifestUrl;
            media.addEventListener('loadedmetadata', () => {
                if (savedPosition > 0) media.currentTime = savedPosition;
                if (restorePlayback) media.play().catch(() => {});
            }, {once: true});
            return;
        }
        announce('تشغيل الفيديو غير مدعوم في هذا المتصفح.');
    };

    const announce = (message) => { player.querySelector('[data-player-announcement]').textContent = message; };
    const render = () => {
        seek.value = String(position);
        seek.style.setProperty('--played', `${position / duration * 100}%`);
        seek.setAttribute('aria-valuetext', `${formatTime(position)} من ${formatTime(duration)}`);
        player.querySelector('[data-player-time]').textContent = `${formatTime(position)} / ${formatTime(duration)}`;
        player.querySelector('[data-watched-marker]').style.insetInlineStart = `${watched / duration * 100}%`;
        player.classList.toggle('is-playing', playing);
        player.querySelectorAll('[data-play]').forEach((button) => {
            button.setAttribute('aria-label', playing ? 'إيقاف الدرس مؤقتاً' : 'تشغيل الدرس');
            button.setAttribute('aria-pressed', String(playing));
            const icon = button.querySelector('[data-play-icon]');
            if (icon) icon.textContent = playing ? 'Ⅱ' : '▶';
        });
        const noteTime = page?.querySelector('[data-note-time]');
        if (noteTime) noteTime.textContent = formatTime(position);
        const completeButton = page?.querySelector('[data-complete-lesson]');
        if (completeButton) {
            completeButton.setAttribute('aria-pressed', String(completed));
            completeButton.classList.toggle('is-completed', completed);
            completeButton.querySelector('[data-complete-label]').textContent = completed ? 'الدرس مكتمل' : 'تحديد كمكتمل';
        }
    };
    const persist = (event = 'heartbeat', isPlaying = playing) => {
        saveProgress(lessonId, { position: Math.floor(position), watched: Math.floor(watched), completed, duration }, {event, isPlaying});
    };
    document.addEventListener('academy:progress', (event) => {
        if (event.detail?.lessonId !== String(lessonId)) return;
        watched = event.detail.progress.watched;
        completed = event.detail.progress.completed;
        render();
    });
    const pause = () => {
        if (media && !media.paused) media.pause();
        const wasPlaying = playing;
        playing = false;
        if (!media || !wasPlaying) persist('paused', false);
        render();
    };
    const togglePlayback = () => {
        if (media) {
            if (media.paused) media.play().catch(() => announce('تعذّر تشغيل الفيديو.'));
            else media.pause();
            return;
        }
        if (playing) return pause();
        if (position >= duration) position = 0;
        playing = true;
        lastTick = performance.now();
        resume.hidden = true;
        render();
    };
    const seekTo = (seconds) => {
        position = Math.max(0, Math.min(duration, Number(seconds) || 0));
        if (media && Number.isFinite(media.duration)) media.currentTime = position;
        lastTick = performance.now();
        render();
    };
    const updateVolume = (nextVolume) => {
        volume = Math.min(1, Math.max(0, nextVolume));
        if (media) media.volume = volume;
        player.querySelector('[data-volume]').value = String(volume);
        const muted = volume === 0;
        player.querySelector('[data-mute]').setAttribute('aria-label', muted ? 'تشغيل الصوت' : 'كتم الصوت');
        player.querySelector('[data-mute]').setAttribute('aria-pressed', String(muted));
        player.querySelector('[data-mute]').classList.toggle('is-muted', muted);
        player.dataset.volume = String(volume);
    };
    const toggleFullscreen = async () => {
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else if (player.requestFullscreen) await player.requestFullscreen();
            else announce('ملء الشاشة غير متاح في هذا المتصفح.');
        } catch {
            announce('تعذر فتح ملء الشاشة. جرّب من زر المشغّل.');
        }
    };

    if (position > 0 && position < duration) {
        resume.hidden = false;
        player.querySelector('[data-resume-time]').textContent = formatTime(position);
    }
    player.querySelectorAll('[data-play]').forEach((button) => button.addEventListener('click', togglePlayback));
    player.querySelectorAll('[data-skip]').forEach((button) => button.addEventListener('click', () => seekTo(position + Number(button.dataset.skip))));
    seek.addEventListener('input', () => seekTo(seek.value));
    seek.addEventListener('change', () => persist('seeked'));
    player.querySelector('[data-speed]').addEventListener('change', (event) => {
        playbackRate = Number(event.target.value);
        if (media) media.playbackRate = playbackRate;
        persistPreference({playback_speed: playbackRate});
        player.dataset.speed = String(playbackRate);
    });
    player.querySelector('[data-speed]').value = String(playbackRate);
    player.querySelector('[data-volume]').addEventListener('input', (event) => updateVolume(Number(event.target.value)));
    player.querySelector('[data-mute]').addEventListener('click', () => {
        if (volume > 0) { previousVolume = volume; updateVolume(0); }
        else updateVolume(previousVolume || 0.8);
        persistPreference({is_muted: volume === 0});
    });
    player.querySelector('[data-fullscreen]').addEventListener('click', toggleFullscreen);
    document.addEventListener('fullscreenchange', () => {
        player.querySelector('[data-fullscreen]').setAttribute('aria-label', document.fullscreenElement === player ? 'إنهاء ملء الشاشة' : 'ملء الشاشة');
    });
    player.querySelector('[data-restart]').addEventListener('click', () => { seekTo(0); resume.hidden = true; persist('seeked'); });
    player.querySelector('[data-resume-dismiss]').addEventListener('click', () => { resume.hidden = true; });
    page?.querySelector('[data-complete-lesson]')?.addEventListener('click', () => {
        announce('يُحتسب إكمال الدرس بعد مشاهدة ٩٠٪ من مدته.');
    });
    page?.querySelectorAll('[data-seek-to]').forEach((button) => button.addEventListener('click', () => seekTo(button.dataset.seekTo)));
    player.addEventListener('keydown', (event) => {
        if (event.target.closest('input, textarea, select, [contenteditable="true"]') || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.code === 'Space' && event.target.closest('button')) return;
        const rtl = getComputedStyle(player).direction === 'rtl';
        if (event.code === 'Space') { event.preventDefault(); togglePlayback(); }
        else if (event.key === 'ArrowRight') { event.preventDefault(); seekTo(position + (rtl ? -10 : 10)); }
        else if (event.key === 'ArrowLeft') { event.preventDefault(); seekTo(position + (rtl ? 10 : -10)); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); updateVolume(volume + 0.1); }
        else if (event.key === 'ArrowDown') { event.preventDefault(); updateVolume(volume - 0.1); }
        else if (event.key.toLowerCase() === 'f') { event.preventDefault(); toggleFullscreen(); }
        else if (event.key.toLowerCase() === 'm') { event.preventDefault(); player.querySelector('[data-mute]').click(); }
    });

    if (media) {
        media.addEventListener('timeupdate', () => {
            position = media.currentTime || 0;
            duration = Number.isFinite(media.duration) ? media.duration : duration;
            render();
        });
        media.addEventListener('play', () => {
            playing = true;
            lastTick = performance.now();
            resume.hidden = true;
            if (!watchStarted && window.reportStudentActivity) {
                watchStarted = true;
                window.reportStudentActivity('watch_started', lessonId);
            }
            persist('started', true);
            render();
        });
        media.addEventListener('pause', () => { playing = false; persist('paused', false); render(); });
        media.addEventListener('ended', () => { position = duration; pause(); });
        media.addEventListener('contextmenu', (event) => event.preventDefault());
        media.addEventListener('loadedmetadata', () => { duration = Number.isFinite(media.duration) ? media.duration : duration; render(); });
        refreshStream().catch(() => announce('تعذّر بدء بث الفيديو.'));
        streamRenewalTimer = window.setInterval(() => refreshStream(Boolean(media.paused === false)).catch(() => announce('انتهت جلسة البث. أعد تحميل الدرس.')), 5 * 60 * 1000);
    }
    if (!media) window.setInterval(() => {
        const now = performance.now();
        if (playing) {
            position = Math.min(duration, position + (now - lastTick) / 1000 * playbackRate);
            if (position >= duration) { completed = true; pause(); }
            render();
        }
        lastTick = now;
    }, 250);
    progressHeartbeatTimer = window.setInterval(() => {
        if (playing) persist('heartbeat', true);
    }, Math.max(10, Number(document.body.dataset.progressHeartbeat || 12)) * 1000);
    const stage = player.querySelector('[data-player-stage]');
    const watermarkText = player.dataset.preview === 'true' ? 'معاينة تعليمية' : (document.body.dataset.studentWatermark || 'محتوى تعليمي مرخّص');
    const reportTamper = () => {
        if (media && !media.paused) media.pause();
        pause();
        announce('توقّف العرض بعد رصد محاولة إخفاء بصمة المحتوى.');
        document.dispatchEvent(new CustomEvent('protection:watermark-tamper', {detail: {lessonId}}));
    };
    const ensureWatermark = () => {
        let watermark = player.querySelector('[data-player-watermark]');
        let changed = false;
        if (!watermark) {
            watermark = document.createElement('span');
            watermark.className = 'player-watermark';
            watermark.dataset.playerWatermark = '';
            watermark.setAttribute('aria-hidden', 'true');
            watermark.textContent = watermarkText;
            stage?.append(watermark);
            changed = true;
        }
        if (watermark.textContent !== watermarkText) watermark.textContent = watermarkText;
        const style = getComputedStyle(watermark);
        const bounds = watermark.getBoundingClientRect();
        if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) < 0.12 || bounds.width < 30 || bounds.height < 8) {
            watermark.style.removeProperty('display');
            watermark.style.removeProperty('visibility');
            watermark.style.opacity = '.48';
            changed = true;
        }
        if (changed) reportTamper();
    };
    ensureWatermark();
    watermarkObserver = new MutationObserver(() => ensureWatermark());
    if (stage) watermarkObserver.observe(stage, {subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'hidden']});
    window.setInterval(() => {
        const watermark = player.querySelector('[data-player-watermark]');
        if (!watermark) return;
        watermark.style.insetInlineStart = `${8 + Math.random() * 48}%`;
        watermark.style.top = `${10 + Math.random() * 58}%`;
    }, 10000);
    auditHeartbeatTimer = window.setInterval(() => {
        if (playing && window.reportStudentActivity) window.reportStudentActivity('watch_heartbeat', lessonId);
    }, 60000);
    document.addEventListener('student:session-ended', pause);
    window.addEventListener('pagehide', () => {
        if (hls) hls.destroy();
        if (streamRenewalTimer) clearInterval(streamRenewalTimer);
        if (progressHeartbeatTimer) clearInterval(progressHeartbeatTimer);
        if (auditHeartbeatTimer) clearInterval(auditHeartbeatTimer);
        watermarkObserver?.disconnect();
    }, {once: true});
    document.addEventListener('visibilitychange', () => { if (document.hidden && playing) pause(); });
    window.addEventListener('pagehide', () => {
        const finalPosition = media?.currentTime ?? position;
        playing = false;
        flushProgress(lessonId, {position: Math.floor(finalPosition), duration, isPlaying: false});
    }, {once: true});
    render();
    updateVolume(volume);

    player.academyPlayer = {
        ...qualityAdapter,
        attachHls: qualityAdapter.attachHls,
        setEffectiveQuality: qualityAdapter.setEffectiveQuality,
        seekTo,
        pause,
        saveProgress: persist,
        get state() { return { position, watched, completed, playing, duration, playbackRate, volume }; },
    };
    return player.academyPlayer;
}

function initializeLessonPage(page, player) {
    const lesson = JSON.parse(page.dataset.lessonPayload);
    const toast = (message) => {
        const element = page.querySelector('[data-lesson-toast]');
        element.textContent = message;
        element.hidden = false;
        window.clearTimeout(element.hideTimer);
        element.hideTimer = window.setTimeout(() => { element.hidden = true; }, 3500);
    };
    const updateCurriculum = () => {
        const lessons = [...page.querySelectorAll('[data-curriculum-lesson]')];
        let completeCount = 0;
        lessons.forEach((link) => {
            const completed = getProgress(link.dataset.curriculumLesson).completed;
            link.querySelector('[data-lesson-check]').textContent = completed ? '✓' : '';
            link.classList.toggle('is-completed', completed);
            if (completed) completeCount++;
        });
        const percentage = lessons.length ? Math.round(completeCount / lessons.length * 100) : 0;
        page.querySelector('[data-curriculum-completed]').textContent = String(completeCount);
        page.querySelector('[data-curriculum-bar]').style.width = `${percentage}%`;
        page.querySelector('[data-lesson-percent]').textContent = `${percentage}%`;
        page.querySelector('[data-lesson-progress-ring]').style.setProperty('--percentage', `${percentage}%`);
    };
    document.addEventListener('academy:progress', updateCurriculum);
    window.addEventListener('storage', updateCurriculum);
    updateCurriculum();

    const mobileCurriculum = matchMedia('(max-width: 1000px)');
    const sidebar = page.querySelector('.curriculum-sidebar');
    const toggle = page.querySelector('[data-curriculum-toggle]');
    const setCurriculumOpen = (open, focus = false) => {
        page.classList.toggle('curriculum-is-closed', !open);
        toggle.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('curriculum-drawer-open', open && mobileCurriculum.matches);
        if (open && mobileCurriculum.matches) {
            sidebar.setAttribute('role', 'dialog');
            sidebar.setAttribute('aria-modal', 'true');
            if (focus) page.querySelector('[data-curriculum-close]').focus();
        } else {
            sidebar.removeAttribute('role');
            sidebar.removeAttribute('aria-modal');
            if (focus) toggle.focus();
        }
    };
    setCurriculumOpen(!mobileCurriculum.matches);
    mobileCurriculum.addEventListener('change', () => setCurriculumOpen(!mobileCurriculum.matches));
    toggle.addEventListener('click', () => setCurriculumOpen(page.classList.contains('curriculum-is-closed'), true));
    page.querySelector('[data-curriculum-close]').addEventListener('click', () => setCurriculumOpen(false, true));
    page.querySelector('[data-curriculum-backdrop]').addEventListener('click', () => setCurriculumOpen(false, true));
    document.addEventListener('keydown', (event) => {
        if (!mobileCurriculum.matches || page.classList.contains('curriculum-is-closed')) return;
        if (event.key === 'Escape') { event.preventDefault(); setCurriculumOpen(false, true); }
        if (event.key === 'Tab') {
            const controls = [...sidebar.querySelectorAll('button, a, summary')].filter(element => element.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });

    const tabs = [...page.querySelectorAll('[data-lesson-tab]')];
    const selectTab = (tab) => {
        tabs.forEach((button) => {
            const active = button === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
        });
        page.querySelectorAll('[data-lesson-panel]').forEach((panel) => { panel.hidden = panel.dataset.lessonPanel !== tab.dataset.lessonTab; });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', (event) => {
            let nextIndex;
            if (event.key === 'ArrowLeft') nextIndex = (index + 1) % tabs.length;
            else if (event.key === 'ArrowRight') nextIndex = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = tabs.length - 1;
            else return;
            event.preventDefault();
            selectTab(tabs[nextIndex]);
            tabs[nextIndex].focus();
        });
    });

    const playlistKey = `academy:playlist:${storageScope()}`;
    const storedPlaylist = readPreference(playlistKey, []);
    let playlist = Array.isArray(storedPlaylist) ? storedPlaylist.filter((item) => item && typeof item === 'object' && item.id != null) : [];
    const playlistButton = page.querySelector('[data-player-playlist]');
    const renderPlaylist = () => {
        const added = playlist.some((item) => String(item.id) === String(lesson.id));
        playlistButton.setAttribute('aria-pressed', String(added));
        playlistButton.querySelector('[data-playlist-label]').textContent = added ? 'في قائمة التشغيل' : 'قائمة التشغيل';
    };
    playlistButton.addEventListener('click', () => {
        const exists = playlist.some((item) => String(item.id) === String(lesson.id));
        playlist = exists ? playlist.filter((item) => String(item.id) !== String(lesson.id)) : [...playlist, lesson];
        writePreference(playlistKey, playlist);
        renderPlaylist();
        toast(exists ? 'أُزيل الدرس من قائمة التشغيل' : 'أُضيف الدرس إلى قائمة التشغيل');
        document.dispatchEvent(new CustomEvent('academy:playlist', { detail: { playlist } }));
    });
    renderPlaylist();

    const notesKey = `academy:notes:${storageScope()}:${lesson.id}`;
    const storedNotes = readPreference(notesKey, []);
    let notes = Array.isArray(storedNotes) ? storedNotes.filter((note) => note && typeof note.text === 'string' && Number.isFinite(note.position)) : [];
    const renderNotes = () => {
        const list = page.querySelector('[data-notes-list]');
        list.replaceChildren();
        page.querySelector('[data-notes-empty]').hidden = notes.length > 0;
        notes.forEach((note, index) => {
            const item = document.createElement('article');
            item.className = 'lesson-note';
            const time = document.createElement('button');
            time.type = 'button';
            time.className = 'transcript-time';
            time.dir = 'ltr';
            time.textContent = formatTime(note.position);
            time.addEventListener('click', () => player.seekTo(note.position));
            const text = document.createElement('p');
            text.textContent = note.text;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'icon-btn';
            remove.textContent = '×';
            remove.setAttribute('aria-label', 'حذف الملاحظة');
            remove.addEventListener('click', () => {
                notes.splice(index, 1);
                writePreference(notesKey, notes);
                renderNotes();
            });
            item.append(time, text, remove);
            list.append(item);
        });
    };
    page.querySelector('[data-note-form]').addEventListener('submit', (event) => {
        event.preventDefault();
        const input = event.target.elements.note;
        if (!input.value.trim()) return;
        notes.unshift({ text: input.value.trim().slice(0, 2000), position: Math.floor(player.state.position) });
        writePreference(notesKey, notes);
        input.value = '';
        renderNotes();
        toast('تم حفظ الملاحظة');
    });
    renderNotes();
}

export function initPlayers() {
    document.querySelectorAll('[data-academy-player]').forEach((element) => {
        if (element.dataset.initialized) return;
        if (!element.querySelector('[data-hls-video]') && element.dataset.preview !== 'true') return;
        const player = initializePlayer(element);
        const page = element.closest('[data-lesson-page]');
        if (page) { initializeLessonPage(page, player); page.dataset.detailsInitialized = 'true'; }
    });
    document.querySelectorAll('[data-lesson-page]').forEach(page => {
        if (page.dataset.detailsInitialized) return;
        initializeLessonPage(page, {seekTo: () => {}, get state() { return {position: 0}; }});
        page.dataset.detailsInitialized = 'true';
    });
}

document.querySelectorAll('[data-video-pending-url]').forEach(panel => {
    if (!['queued', 'processing'].includes(panel.dataset.videoPendingStatus)) return;
    const timer = setInterval(async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(panel.dataset.videoPendingUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) { clearInterval(timer); return; }
            const {status} = await response.json();
            if (status === 'ready') { clearInterval(timer); location.reload(); }
            else if (status === 'failed') { clearInterval(timer); panel.querySelector('.eyebrow').textContent = 'تعذّر تجهيز الفيديو'; }
        } catch { /* Retry on the next tick after a temporary connection failure. */ }
    }, 5000);
    window.addEventListener('pagehide', () => clearInterval(timer), {once: true});
});
