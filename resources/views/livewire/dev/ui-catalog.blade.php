{{--
    UI 컴포넌트 카탈로그 (개발 전용, /dev/ui). 설명은 app/Livewire/Dev/UiCatalog.php 참고.
    새 공통 컴포넌트를 만들면 아래에 <x-dev.example> 한 칸을 추가하세요.
--}}
<div>
    <x-slot:title>UI 카탈로그 | {{ brand_name() }}</x-slot>

    <div class="flex w-full max-w-none flex-col gap-6" x-data="themeControls()">
        <header>
            <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">UI 컴포넌트 카탈로그</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                공통 컴포넌트를 한 화면에서 보고, "코드 보기"의 코드를 복사해 쓰세요. 개발 모드에서만 열립니다.
            </p>
        </header>

        {{-- 1. 화면 크기 스위치: 바꾸면 이 페이지의 모든 예제가 바로 따라 바뀝니다. --}}
        <x-application.settings-section title="화면 크기로 확인하기"
            description="밀도와 글자 크기를 바꿔 가며 내 화면이 모든 크기에서 잘 보이는지 확인하세요. 설정은 [프로필 → 화면 설정]과 같습니다.">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-neutral-500 dark:text-fg-dim">밀도</span>
                    @foreach (['compact' => '촘촘하게', 'comfortable' => '편안하게', 'touch' => '터치', 'auto' => '자동'] as $value => $label)
                        <button type="button" class="button" @click="setDensity('{{ $value }}')"
                            :class="density === '{{ $value }}' ? 'button-highlighted' : ''">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-neutral-500 dark:text-fg-dim">글자</span>
                    @foreach (['small' => '작게', 'normal' => '보통', 'large' => '크게', 'xlarge' => '아주 크게'] as $value => $label)
                        <button type="button" class="button" @click="setTextSize('{{ $value }}')"
                            :class="textSize === '{{ $value }}' ? 'button-highlighted' : ''">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </x-application.settings-section>

        {{-- 2. 디자인 토큰: 현재 적용된 값을 실시간으로 읽어 보여 줍니다. --}}
        <x-dev.example title="디자인 토큰 (resources/css/design-tokens.css)"
            description="위에서 크기를 바꾸면 값이 바로 갱신됩니다. 모든 버튼·입력·메뉴의 높이가 이 값을 읽습니다.">
            <div x-data="{
                    names: ['--ui-h-sm', '--ui-h-md', '--ui-h-lg', '--ui-h-xl', '--ui-text-control', '--ui-list-option-height', '--ui-table-row-height', '--ui-table-header-height', '--ui-text-scale'],
                    values: {},
                    read() {
                        const style = getComputedStyle(document.documentElement);
                        this.names.forEach(name => this.values[name] = style.getPropertyValue(name).trim());
                        this.attrs = `data-density=${document.documentElement.dataset.density} · data-text-size=${document.documentElement.dataset.textSize}`;
                    },
                    attrs: '',
                }" x-init="read()" @ui-preferences-changed.window="$nextTick(() => read())">
                <p class="mb-3 font-mono text-xs text-coollabs dark:text-warning" x-text="attrs"></p>
                <div class="grid gap-x-6 gap-y-1 font-mono text-xs sm:grid-cols-2">
                    <template x-for="name in names" :key="name">
                        <div class="flex items-center justify-between border-b border-neutral-100 py-1 dark:border-white/[0.05]">
                            <span class="text-neutral-500 dark:text-fg-dim" x-text="name"></span>
                            <span class="font-semibold text-black dark:text-fg" x-text="values[name]"></span>
                        </div>
                    </template>
                </div>
            </div>
            <x-slot:code>
@verbatim
/* resources/css/design-tokens.css */
[data-density="compact"] {
    --ui-h-md: 2rem;   /* 버튼·입력·메뉴 높이 */
}

/* 새 컴포넌트에서는 Tailwind 클래스(h-8)나 변수를 그대로 쓰면 밀도 설정을 따라갑니다. */
.my-control { height: var(--ui-h-md); }
@endverbatim
            </x-slot:code>
        </x-dev.example>

        <div class="grid items-start gap-6 xl:grid-cols-2">
            {{-- 3. 버튼 --}}
            <x-dev.example title="버튼" description="기본 / 강조 / 위험 / 비활성 / 아이콘 버튼. 높이는 밀도 설정을 따릅니다.">
                <div class="flex flex-wrap items-center gap-2">
                    <x-forms.button type="button">기본</x-forms.button>
                    <x-forms.button type="button" isHighlighted>강조</x-forms.button>
                    <x-forms.button type="button" isError>삭제</x-forms.button>
                    <x-forms.button type="button" disabled>비활성</x-forms.button>
                    <button type="button" class="icon-button" aria-label="설정"><x-reicon name="settings" class="size-4" /></button>
                </div>
                <x-slot:code>
@verbatim
<x-forms.button type="button" wire:click="save">기본</x-forms.button>
<x-forms.button type="submit" isHighlighted>강조</x-forms.button>
<x-forms.button type="button" isError>삭제</x-forms.button>
<button type="button" class="icon-button" aria-label="설정">
    <x-reicon name="settings" class="size-4" />
</button>
@endverbatim
                </x-slot:code>
            </x-dev.example>

            {{-- 4. 입력 --}}
            <x-dev.example title="입력 / 선택 / 체크박스" description="id 가 Livewire 의 public 속성 이름과 연결됩니다 (wire:model 자동).">
                <div class="grid gap-3">
                    <x-forms.input id="demoName" label="이름" required helper="소문자, 숫자, 하이픈만 사용합니다." />
                    <x-forms.input id="demoSecret" type="password" label="비밀번호" placeholder="입력하세요" />
                    <x-forms.textarea id="demoDescription" label="설명" placeholder="여러 줄 입력" />
                    <x-forms.listbox id="demoChoice" label="빌드 방식" :options="[
                        ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                        ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                        ['value' => 'static', 'label' => '정적 사이트'],
                    ]" />
                    <x-forms.checkbox id="demoEnabled" label="자동 배포 사용" helper="푸시하면 자동으로 배포합니다." />
                </div>
                <x-slot:code>
@verbatim
<x-forms.input id="name" label="이름" required helper="도움말" />
<x-forms.textarea id="description" label="설명" />
<x-forms.listbox id="buildPack" label="빌드 방식" :options="[
    ['value' => 'nixpacks', 'label' => 'Nixpacks'],
    ['value' => 'static', 'label' => '정적 사이트'],
]" />
<x-forms.checkbox id="autoDeploy" label="자동 배포 사용" />
@endverbatim
                </x-slot:code>
            </x-dev.example>
        </div>

        {{-- 5. 카드 / 안내 / 상태 --}}
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-dev.example title="설정 카드 (settings-section)" description="화면의 한 묶음은 이 카드로 감쌉니다. 카드 사이 간격은 gap-6.">
                <x-application.settings-section title="일반" description="카드 제목과 설명 예제입니다.">
                    <p class="text-sm text-neutral-600 dark:text-fg-dim">여기에 입력 폼이나 표를 넣습니다.</p>
                </x-application.settings-section>
                <x-slot:code>
@verbatim
<x-application.settings-section title="일반" description="설명">
    <x-forms.input id="name" label="이름" />
</x-application.settings-section>
@endverbatim
                </x-slot:code>
            </x-dev.example>

            <x-dev.example title="안내(callout) / 상태 배지 / 빈 화면" description="상태는 색만으로 전달하지 말고 글자와 함께 보여 주세요.">
                <div class="grid gap-2">
                    <x-callout type="info" title="정보">참고하면 좋은 내용입니다.</x-callout>
                    <x-callout type="warning" title="주의">되돌릴 수 없는 작업입니다.</x-callout>
                    <x-callout type="danger" title="위험">데이터가 삭제됩니다.</x-callout>
                    <x-callout type="success" title="완료">저장했습니다.</x-callout>
                    <div class="flex flex-wrap gap-2">
                        <x-status-badge status="실행 중" type="success" />
                        <x-status-badge status="대기" type="warning" />
                        <x-status-badge status="실패" type="error" />
                        <x-status-badge status="알 수 없음" type="neutral" />
                    </div>
                    <x-empty size="sm" icon-name="file-content" title="항목이 없습니다" description="새 항목을 추가해 보세요." />
                </div>
                <x-slot:code>
@verbatim
<x-callout type="warning" title="주의">본문</x-callout>   {{-- info | warning | danger | success --}}
<x-status-badge status="실행 중" type="success" />        {{-- neutral | success | warning | error --}}
<x-empty size="sm" icon-name="file-content" title="항목이 없습니다" description="설명" />
@endverbatim
                </x-slot:code>
            </x-dev.example>
        </div>

        {{-- 6. 표 / 메뉴 --}}
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-dev.example title="표 (data-table)" description="행 높이는 밀도 설정을 따릅니다. 좁은 화면에서는 가로 스크롤됩니다.">
                <div class="data-table overflow-x-auto rounded-lg border border-neutral-200 dark:border-white/[0.07]">
                    <div class="data-table-header min-w-[420px] grid-cols-[1fr_8rem_6rem]"><span>이름</span><span>상태</span><span>포트</span></div>
                    @foreach ([['web', '실행 중', '3000'], ['worker', '대기', '-'], ['db', '실패', '5432']] as $row)
                        <div class="data-table-row min-w-[420px] grid-cols-[1fr_8rem_6rem] border-t border-neutral-200 text-[13px] dark:border-white/[0.07]">
                            <span class="font-medium text-black dark:text-fg">{{ $row[0] }}</span>
                            <span>{{ $row[1] }}</span>
                            <span class="font-mono">{{ $row[2] }}</span>
                        </div>
                    @endforeach
                </div>
                <x-slot:code>
@verbatim
<div class="data-table">
    <div class="data-table-header grid-cols-[1fr_8rem]"><span>이름</span><span>상태</span></div>
    @foreach ($items as $item)
        <div wire:key="item-{{ $item->id }}" class="data-table-row grid-cols-[1fr_8rem]">
            <span>{{ $item->name }}</span><span>{{ $item->status }}</span>
        </div>
    @endforeach
</div>
@endverbatim
                </x-slot:code>
            </x-dev.example>

            <x-dev.example title="메뉴 항목 (menu-item)" description="사이드바와 같은 모양입니다. 선택된 항목은 menu-item-active.">
                <div class="flex max-w-xs flex-col gap-0.5">
                    <span class="menu-item"><x-reicon name="dashboard" class="menu-item-icon" /><span class="menu-item-label">대시보드</span></span>
                    <span class="menu-item menu-item-active"><x-reicon name="projects" class="menu-item-icon" /><span class="menu-item-label">프로젝트</span></span>
                    <span class="menu-item"><x-reicon name="servers" class="menu-item-icon" /><span class="menu-item-label">서버</span></span>
                </div>
                <x-slot:code>
@verbatim
<a href="{{ route('projects') }}" @class(['menu-item', 'menu-item-active' => request()->routeIs('projects')])>
    <x-reicon name="projects" class="menu-item-icon" />
    <span class="menu-item-label">프로젝트</span>
</a>
@endverbatim
                </x-slot:code>
            </x-dev.example>
        </div>

        {{-- 7. 확인 창 / 토스트 --}}
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-dev.example title="확인 창 (modal-confirmation)" description="되돌릴 수 없는 작업은 반드시 이 컴포넌트로 확인을 받습니다. (브라우저 기본 confirm 은 쓰지 않습니다.)">
                <x-modal-confirmation title="예제 항목을 삭제할까요?" buttonTitle="삭제" isErrorButton submitAction="confirmDemo"
                    :confirmWithText="false" :confirmWithPassword="false"
                    :actions="['이 작업은 되돌릴 수 없습니다.']" />
                <x-slot:code>
@verbatim
<x-modal-confirmation title="항목을 삭제할까요?" buttonTitle="삭제" isErrorButton
    submitAction="delete" :confirmWithText="false" :confirmWithPassword="false"
    :actions="['이 작업은 되돌릴 수 없습니다.']" />
@endverbatim
                </x-slot:code>
            </x-dev.example>

            <x-dev.example title="토스트(알림)" description="저장/오류 같은 결과는 토스트로 알립니다. 서버 코드: $this->dispatch('success', '제목', '설명')">
                <div class="flex flex-wrap gap-2">
                    @foreach (['success' => '성공', 'error' => '오류', 'warning' => '경고', 'info' => '정보'] as $type => $label)
                        <x-forms.button type="button" wire:click="showToast('{{ $type }}')">{{ $label }}</x-forms.button>
                    @endforeach
                </div>
                <x-slot:code>
@verbatim
// Livewire 컴포넌트 안에서
$this->dispatch('success', __('Settings saved.'));
$this->dispatch('error', __('Failed to deploy.'), __('Check the logs.'));
@endverbatim
                </x-slot:code>
            </x-dev.example>
        </div>

        {{-- 8. 색 토큰 --}}
        <x-dev.example title="색 토큰" description="배경과 글자색은 라이트/다크 모두에서 확인하세요. 새 색을 만들기보다 아래 토큰을 쓰세요.">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ([
                    ['bg-app', '화면 바탕'], ['bg-panel', '사이드바·상단'], ['bg-surface', '카드'], ['bg-raised', '올라온 카드'],
                    ['bg-coollabs', '강조(라이트)'], ['bg-warning', '강조(다크)'], ['bg-success', '성공'], ['bg-error', '오류'],
                ] as [$class, $label])
                    <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.07]">
                        <div class="h-12 {{ $class }}"></div>
                        <div class="px-2 py-1.5">
                            <div class="font-mono text-[11px] text-black dark:text-fg">{{ $class }}</div>
                            <div class="text-[11px] text-neutral-500 dark:text-fg-dim">{{ $label }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-dev.example>
    </div>
</div>
