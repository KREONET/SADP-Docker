<?php

namespace App\Livewire\Dev;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * UI 컴포넌트 카탈로그 (개발 전용, 주소: /dev/ui).
 *
 * 공통 컴포넌트(버튼, 입력, 카드, 표, 확인 창 등)와 디자인 토큰을 한 화면에 모아 보여 주고,
 * 각 예제 옆에 그대로 복사해 쓸 수 있는 코드를 함께 보여 줍니다.
 *
 * 이렇게 쓰세요:
 *  - 새 화면을 만들기 전에 여기서 쓸 수 있는 컴포넌트가 있는지 먼저 찾아보세요.
 *  - 위쪽의 "화면 크기" 스위치로 밀도(촘촘하게/편안하게/터치)와 글자 크기를 바꿔 가며
 *    내 화면이 어떻게 보이는지 확인하세요.
 *  - 새 공통 컴포넌트를 만들었다면 이 화면에 예제를 한 칸 추가하세요
 *    (resources/views/livewire/dev/ui-catalog.blade.php).
 *
 * 실제 서비스(production)에서는 열리지 않습니다. 라우트도 개발/테스트 환경에서만 등록되고,
 * mount() 에서 한 번 더 막습니다 (화면을 숨기는 것만으로는 보호가 되지 않으므로).
 */
class UiCatalog extends Component
{
    /** 입력 예제에 연결된 값들. Livewire 입력은 같은 이름의 public 속성에 연결됩니다. */
    public string $demoName = 'my-application';

    public string $demoDescription = '';

    public string $demoSecret = '';

    public string $demoChoice = 'nixpacks';

    public bool $demoEnabled = true;

    public function mount(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
    }

    /**
     * 토스트(오른쪽 아래 알림) 예제. 서버 코드에서는 이렇게 보냅니다:
     * $this->dispatch('success', '제목', '설명');  // success | error | warning | info
     */
    public function showToast(string $type): void
    {
        abort_unless(in_array($type, ['success', 'error', 'warning', 'info'], true), 404);

        $this->dispatch($type, '토스트 예제', '서버에서 보낸 '.$type.' 알림입니다.');
    }

    /** 확인 창(x-modal-confirmation)이 확인되었을 때 실행되는 예제 동작. */
    public function confirmDemo(): void
    {
        $this->dispatch('success', '확인되었습니다.', '실제 화면에서는 여기서 삭제 같은 작업을 실행합니다.');
    }

    public function render(): View
    {
        return view('livewire.dev.ui-catalog');
    }
}
