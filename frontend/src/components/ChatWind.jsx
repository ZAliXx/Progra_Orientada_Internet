import "../styles/chatwind.css";

export default function ChatWindow({ onOpenTasks }) {
  return (
    <div className="chat-window">
      
      <div className="chat-header">
        <div className="user-info">
          <img className="back" src="back_icon_white.png"></img>
          <div className="avatar">
            <img src="/user_icon_white.png"></img>
          </div>
          <span>User name</span>
        </div>

          <div className="chat-actions">
            <button className="chat-call">
              <img src="/videocall_icon.png"></img>
              <p>Call</p>
            </button>
            <button className="chat-more" onClick={onOpenTasks}>
              <img src="dots_icon_black.png"></img>
            </button>
          </div>
      </div>

      <div className="messages">
        <div className="msg received">Lorem ipsum dolore</div>
        <div className="msg sent">Lorem ipsum dolore</div>
      </div>

      <div className="chat-input">
        <button className="plus">
          <img src="plussign_icon_black.png"></img>
        </button>
        <input placeholder="Write message..." />
        <button className="send">
          <img src="send_icon_black.png"></img>
        </button>
      </div>

    </div>
  );
}