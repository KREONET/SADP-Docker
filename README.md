<div align="center">

# PADED

**Coolify기반 쉽게 앱을 배포하고 여러가지 위험을 방지위해 추가로 기능을 넣은 플랫폼**

</div>

## 왜 PADED 따로 나누었나요?
정부기관이나 연구기관 연구소 같은 특수한 상황속에서는 많은 로그와 제약이 있을수 있습니다. 그래서 이러한 제약사항에서도 연구자들이
AI로 만든 프로그램 및 웹이나 오픈소스를 보다 격리된 환경에서 사용할수 있기 위해 기능을 추가를 해서 만들었습니다.

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
git clone https://github.com/KREONET/SADP-Docker.git
cd SADP-Docker

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
git clone https://github.com/KREONET/SADP-Docker.git /opt/paded
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

### 3. 업데이트는 GitHub에서 확인

PADED에는 앱 안에서 업데이트하는 기능(Upgrade 버튼, 자동 업데이트)이 없습니다. 원본 Coolify의 업데이트 기능은 공식 Coolify 이미지를 받아 와서 PADED를 덮어쓰기 때문에 제거했습니다. 새 버전은 [GitHub Releases](https://github.com/KREONET/SADP-Docker/releases)에서 확인하고, 아래 **PADED 새 버전으로 올리기** 방법으로 올리세요.

### 서버 공유하기 (여러 팀이 같은 서버 사용)

원래 Coolify는 팀마다 서버를 따로 등록해야 합니다. PADED에서는 플랫폼 관리자가 서버 하나를 모든 팀과 공유할 수 있습니다.

1. 플랫폼 관리자(Root 팀 admin/owner)로 로그인합니다.
2. **서버 → 해당 서버 → 공유**에서 **모든 팀과 공유**를 켭니다.
3. 이제 다른 팀(OIDC로 가입한 사용자의 개인 팀 포함)이 새 리소스를 만들 때 이 서버를 고를 수 있습니다.

공유해도 서버 관리(프록시, 삭제, 서버 터미널 등)는 원래 팀의 관리자만 할 수 있습니다. 다른 팀은 자기 리소스와 자기 배포 기록, 로그만 봅니다. 컨테이너에 호스트 권한을 주는 설정(privileged, Docker 소켓·호스트 경로 마운트 등)은 원래대로 플랫폼 관리자만 넣을 수 있습니다. 공유 서버 위 컨테이너의 터미널 접속은 아직 플랫폼 관리자만 됩니다.

### PADED 새 버전으로 올리기

```bash
cd /opt/paded && git pull
sudo bash scripts/paded-deploy.sh
```

올리기 전에 **설정 → Backup**에서 인스턴스 백업을 먼저 받아 두세요.

### 다른 브랜치로 바꾸기 (데이터 유지)

```bash
cd /opt/paded
git fetch origin
git checkout <브랜치>
git pull
sudo bash scripts/paded-deploy.sh
```

새 브랜치의 코드로 이미지를 다시 빌드하고, 새 마이그레이션만 추가로 실행됩니다. 데이터베이스는 **되돌려지지 않으므로**, 마이그레이션이 더 적은 예전 브랜치로 돌아가면 오류가 날 수 있습니다. 그럴 때는 아래처럼 아예 새로 설치하세요.

### 브랜치를 바꾸면서 아예 새로 설치하기 (데이터 삭제)

**PADED의 계정, 프로젝트, 설정, 이 서버에 배포한 앱 데이터가 모두 지워지고 되돌릴 수 없습니다.** 필요한 것은 먼저 백업하고, `/data/coolify/source/.env`도 서버 밖에 복사해 두세요.

1. 기존 PADED를 지웁니다.

   ```bash
   # 프록시와 센티널 컨테이너 삭제 (없으면 그냥 넘어갑니다)
   sudo docker rm -f coolify-proxy coolify-sentinel 2>/dev/null || true

   # PADED 컨테이너, DB·Redis 볼륨, coolify 네트워크 삭제
   cd /data/coolify/source
   sudo docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml down -v --remove-orphans

   # 데이터 폴더(.env, SSH 키, 앱·DB·백업 파일) 삭제
   cd /
   sudo rm -rf /data/coolify
   ```

   이 서버에 배포한 앱 컨테이너가 남아 있으면 `coolify` 네트워크가 지워지지 않습니다. `docker ps -a`로 확인해 지운 뒤 `sudo docker network rm coolify`를 실행하세요.

2. 원하는 브랜치로 바꾼 뒤 처음 설치할 때와 같이 1단계, 2단계를 다시 실행합니다.

   ```bash
   cd /opt/paded
   git fetch origin
   git checkout <브랜치>
   git pull
   sudo bash scripts/install.sh
   sudo bash scripts/paded-deploy.sh
   ```

   `.env`(DB 비밀번호, APP_KEY)와 SSH 키가 새로 만들어지므로, 예전 백업을 복원하려면 1단계에서 복사해 둔 `.env`의 `APP_KEY`가 필요합니다.

> 새로 설치하면 설치 스크립트가 `~/.ssh/authorized_keys`에 `coolify` 주석이 붙은 키를 추가합니다. 예전 키 줄이 남아 있어도 동작에는 문제없지만, 정리하려면 예전 `coolify` 줄을 지우세요.

<!-- ## Quick start

Install Coolify on a supported server with one command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Read the [installation guide](https://coolify.io/docs/installation) for requirements and detailed instructions. You can also review the [installation script](./scripts/install.sh) before you run it. -->
