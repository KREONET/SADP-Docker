<div align="center">

# PADED (Security ver)

**Coolify기반 쉽게 앱을 배포하고 여러가지 위험을 방지위해 추가로 기능을 넣은 플랫폼**

</div>

## 왜 PADED 따로 나누었나요?
정부기관이나 연구기관 연구소 같은 특수한 상황속에서는 많은 로그와 제약이 있을수 있습니다. 그래서 이러한 제약사항에서도 연구자들이
AI로 만든 프로그램 및 웹이나 오픈소스를 보다 격리된 환경에서 사용할수 있기 위해 기능을 추가를 해서 만들었습니다.

> #💡안내사항<br/>
> 현재 이 브런치는 국가보안의 기준을 보고 그에 상응하게 맟춰진 버전입니다.<br/>
> 보려가기  [메인프로젝트라고 달라진점](/SECURITY.md)

## 그리고 Coolify는 뭐에요?
Coolify는 Heroku, Netlify, Vercel을 대체할 수 있는 오픈 소스이자 자체 호스팅(self-hostable)이 가능한 솔루션입니다. 이를 통해 사용자는 자신의 하드웨어에서 서버, 애플리케이션, 데이터베이스를 직접 관리할 수 있으며, SSH 연결만 있으면 바로 이용 가능합니다.

VPS, 베어메탈 서버, 라즈베리 파이(Raspberry Pi) 등 SSH 연결을 지원하는 어떤 서버에서든 사용할 수 있습니다. Coolify는 인프라에 대한 통제권을 유지하면서도 클라우드 플랫폼이 제공하는 편리함을 누릴 수 있게 해줍니다.

모든 설정 정보는 사용자의 서버에 저장됩니다. 따라서 Coolify 사용을 중단하더라도 실행 중인 리소스는 계속 작동하며, 여전히 관리 가능한 상태로 유지됩니다.

## PADED는 어떤 기능을 제공해주나요?
저희는 정부기관에서 요구사항이나 보안을 위해서 아래와 같은 기능이 추가됨니다.
- **앱별 로그 기록:** 앱에서 통신되고있는 트래픽 정보나 앱에서 일어나는 로그를 수집을 할수 있습니다.
- **통합모니터링:** 중앙에 있는 관리자가 한눈에 관리를 할수 있음니다.
- **독립적인공간:** 원래는 독립적인 공간이 있을려면 서버를 분리해서 써야 하지만 여기는 공통된 서버에서 그룹별 및 사용자별로 분리가 됨니다.
- **패키지 취약점 검사:** tinvy라는 보안 스캐너를 이용해서 배포를 할때 패키지별로 검사를 합니다. 이상한게 발견되면 사용자나 레포에 직접적으로 알려줌니다.
- **OIDC지원:** 정부나 연구관에서 별도로 SSO을 사용한 경우 OIDC을 이용해 바로 로그인이 가능합니다.

Coolify와 같이 아래와같은 기능은 공통적으로 제공됨니다.
----------------------------------------------------
- **모든 애플리케이션 배포:** Nixpacks, Railpack, Dockerfile, Docker Compose 또는 사전 빌드된 Docker 이미지를 사용하여 GitHub, GitLab, Bitbucket, Gitea 등에서 빌드 및 배포하세요.
- **데이터베이스 및 서비스 실행:** 관리형 데이터베이스와 300개 이상의 원클릭 서비스를 영구 스토리지 및 자동 생성된 자격 증명과 함께 배포하세요.
- **배포 자동화:** Git 푸시 시 배포, 풀 리퀘스트(PR) 미리보기 생성, 배포 웹훅 호출, 그리고 이전 애플리케이션 이미지로의 롤백 기능을 활용하세요.
- **네트워킹 관리:** 사용자 지정 도메인, 자동 HTTPS 인증서, 리버스 프록시, 상태 확인(health check) 및 컨테이너 네트워크를 설정하세요.
- **인프라 운영:** 다수의 서버 관리, 배포 및 런타임 로그 확인, 컨테이너 터미널 접속, 리소스 상태 모니터링을 수행하세요.
- **워크로드 보호:** 데이터베이스 및 스토리지 백업, 예약 작업, 환경 변수, 보안 정보(secrets), 알림 기능을 설정하세요.
- **워크플로 통합:** 대시보드, API, CLI, MCP 및 팀 단위의 접근 제어 기능을 통해 리소스를 관리하세요.

## 개발 환경 만들기

준비물은 Docker뿐입니다. PHP와 Node는 컨테이너 안에서 돌기 때문에 따로 설치하지 않아도 됩니다.

```bash
git clone https://github.com/KREONET/PASED.git
cd PASED

./scripts/dev start    # 개발 환경 시작
./scripts/dev demo     # 또는: 데모 데이터와 Loki/Grafana까지 함께 시작
./scripts/dev urls     # 접속 주소와 포트 확인 (main 브랜치는 http://localhost:8000)
```

- 데모 환경 로그인: `test@example.com` / `password` (플랫폼 관리자)
- 원격 서버에서 띄웠다면 SSH 터널로 접속합니다: `ssh -L 8000:localhost:8000 <서버>`
- 개발 인스턴스는 git 브랜치마다 따로 만들어집니다.

| 명령 | 하는 일 |
|---|---|
| `./scripts/dev stop` | 내리기 (데이터는 유지) |
| `./scripts/dev start` | 다시 올리기 |
| `./scripts/dev run` | 올리고 로그 보기, 종료하면 같이 내려감 |
| `./scripts/dev exec <명령>` | 앱 컨테이너 안에서 실행 (예: `./scripts/dev exec php artisan migrate`) |
| `./scripts/dev destroy <이름>` | 컨테이너, 볼륨, VM까지 삭제 (되돌릴 수 없음) |

테스트와 화면 수정 방법은 [한국어 개발자 가이드](docs/ko/README.md)를 참고하세요.

## 운영(본) 환경 만들기

PADED는 Coolify 공식 이미지가 아니라 **이 저장소에서 직접 빌드한 이미지**로 실행해야 합니다. 공식 설치 스크립트만 실행하면 PADED 기능이 없는 원본 Coolify가 설치됩니다.

**준비물:** Docker를 설치할 수 있는 Linux 서버 1대, root 또는 sudo 권한, 방화벽에서 `8000`(웹), `6001`(실시간), `6002`(터미널) 포트 허용

### 1. 기본 설치 (디렉터리, 비밀값, SSH 키 생성)

```bash
git clone https://github.com/KREONET/PASED.git /opt/paded
cd /opt/paded
sudo bash scripts/install.sh
```

설치를 시작하면 **"내부 회원가입 기능을 쓸까요? [Y/n]"** 하고 묻습니다.

| 답 | 결과 |
|---|---|
| `Y` (기본값) | 일반 설치. 처음 접속한 사람이 회원가입 화면에서 관리자 계정을 만듭니다. |
| `n` | 회원가입을 끄고 **root 계정만 무작위 비밀번호로 생성**합니다. 설치가 끝나면 화면에 이메일(`root@paded.local`)과 비밀번호가 표시됩니다. root로 로그인한 뒤 **설정 → OAuth**에서 OIDC를 연동하면, 다른 사용자는 OIDC로 가입하고 로그인합니다. |

- 질문 없이 설치하려면 `INTERNAL_SIGNUP=true` 또는 `INTERNAL_SIGNUP=false`를 지정합니다. 예: `sudo INTERNAL_SIGNUP=false bash scripts/install.sh`
- root 계정 정보를 직접 정하려면 `ROOT_USERNAME`, `ROOT_USER_EMAIL`, `ROOT_USER_PASSWORD`도 함께 지정합니다. 비밀번호는 대문자, 소문자, 숫자, 특수문자를 모두 포함한 8자 이상이어야 합니다.
- root 비밀번호는 `/data/coolify/source/.env`에도 저장됩니다. 첫 로그인 후 비밀번호를 바꾸세요.

`/data/coolify/` 아래에 데이터 폴더와 `.env`(DB 비밀번호, APP_KEY 등)가 만들어지고, 일단 원본 Coolify가 실행됩니다. 다음 단계에서 PADED 이미지로 교체합니다.

> root 계정은 **PADED 이미지로 교체한 뒤** 처음 시작될 때 만들어집니다. 원본 Coolify 이미지도 같은 방식으로 root를 만들기 때문에, 1단계에서 이미 만들어질 수도 있습니다.

### 2. PADED 이미지 빌드 후 교체 (필수)

**이 단계를 하지 않으면 저장소에서 고친 내용이 하나도 반영되지 않습니다.** 1단계에서 실행된 것은 공식 Coolify 이미지입니다.

```bash
cd /opt/paded
sudo bash scripts/paded-deploy.sh
```

이 스크립트가 하는 일:
- 이 저장소의 코드로 `coollabsio/coolify:paded` 이미지를 빌드합니다. 처음에는 몇 분 걸립니다.
- 저장소의 compose 파일을 `/data/coolify/source/`에 복사합니다.
- `.env`에 `LATEST_IMAGE=paded`, `AUTOUPDATE=false`를 기록합니다. 그래서 재시작해도 PADED 이미지가 유지되고, 자동 업데이트가 공식 이미지로 덮어쓰지 않습니다.
- 컨테이너를 교체해 실행합니다. DB 마이그레이션은 컨테이너가 시작될 때 자동으로 실행됩니다.

교체가 끝나면 `http://<서버 IP>:8000`에 접속합니다. 내부 회원가입을 켰다면 첫 관리자 계정을 만들고(이 계정이 플랫폼 관리자가 됩니다), 껐다면 설치 끝에 표시된 root 계정으로 로그인합니다.

반영됐는지 확인하려면: `docker inspect coolify -f '{{.Config.Image}}'`가 `docker.io/coollabsio/coolify:paded`여야 합니다.

### 3. Upgrade 버튼 누르지 않기

화면의 **Upgrade** 버튼을 누르면 공식 Coolify 이미지를 받아 와서 PADED를 덮어씁니다. 실수로 눌렀다면 `sudo bash scripts/paded-deploy.sh`를 다시 실행하세요.

### PADED 새 버전으로 올리기

```bash
cd /opt/paded && git pull
sudo bash scripts/paded-deploy.sh
```

올리기 전에 **설정 → Backup**에서 인스턴스 백업을 먼저 받아 두세요.

<!-- ## Quick start

Install Coolify on a supported server with one command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Read the [installation guide](https://coolify.io/docs/installation) for requirements and detailed instructions. You can also review the [installation script](./scripts/install.sh) before you run it. -->
